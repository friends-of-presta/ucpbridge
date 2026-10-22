<?php
/**
 * Session de caisse native (dev.ucp.shopping.checkout + fulfillment) : création (depuis des lignes ou un panier UCP),
 * mise à jour réservée (lignes, acheteur, consentements, livraison, codes promo), acceptation des CGV, annulation.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCheckoutSession
{
    /** @var Context */
    private $context;
    /** @var Ucpbridge */
    private $module;
    /** @var UcpBridgeCaller */
    private $caller;
    /** @var UcpBridgeSessionStore */
    private $store;
    /** @var UcpBridgeCartLines */
    private $lines;
    /** @var UcpBridgeBuyer */
    private $buyer;
    /** @var UcpBridgeFulfillment */
    private $fulfillment;
    /** @var UcpBridgeCheckoutStatus */
    private $status;
    /** @var UcpBridgeCheckoutView */
    private $view;

    public function __construct(Context $context, Ucpbridge $module, UcpBridgeCaller $caller, UcpBridgeSessionStore $store,
        UcpBridgeCartLines $lines, UcpBridgeBuyer $buyer, UcpBridgeFulfillment $fulfillment, UcpBridgeCheckoutStatus $status,
        UcpBridgeCheckoutView $view)
    {
        $this->context = $context;
        $this->module = $module;
        $this->caller = $caller;
        $this->store = $store;
        $this->lines = $lines;
        $this->buyer = $buyer;
        $this->fulfillment = $fulfillment;
        $this->status = $status;
        $this->view = $view;
    }

    private function errorDoc(array $messages, array $capabilities)
    {
        return UcpBridgeMessage::errorDoc($messages, $capabilities, Ucpbridge::UCP_VERSION, $this->context->link->getPageLink('index', true));
    }

    public function createCheckout(array $body)
    {
        $lineItems = $body['line_items'] ?? null;
        if (!$lineItems && !empty($body['cart_id'])) {
            // Conversion d'un panier UCP existant en session de caisse.
            $c = $this->store->load((string) $body['cart_id'], 'cart');
            if (!$c || 'active' !== $c['status'] || !$this->caller->owns($c)) {
                return [404, $this->errorDoc([UcpBridgeMessage::error('not_found', 'Panier introuvable', 'unrecoverable', '$.cart_id')], ['dev.ucp.shopping.checkout'])];
            }
            $lineItems = array_map(function ($l) {
                return ['id' => $l['id'], 'item' => array_filter(['id' => $l['requested_id'], 'variant_id' => $l['requested_variant_id']]), 'quantity' => $l['quantity']];
            }, $c['payload']['lines']);
            if (!isset($body['discounts']) && !empty($c['payload']['discount_codes'])) {
                $body['discounts'] = ['codes' => $c['payload']['discount_codes']];
            }
        }
        list($lines, $errors) = $this->lines->resolveLines($lineItems);
        if ($errors) {
            return [200, $this->errorDoc($errors, ['dev.ucp.shopping.checkout'])];
        }
        $cart = $this->lines->newPsCart();
        if (!$this->lines->fillCart($cart, $lines)) {
            return [200, $this->errorDoc([UcpBridgeMessage::error('out_of_stock', 'Impossible d\'ajouter les articles', 'unrecoverable')], ['dev.ucp.shopping.checkout'])];
        }
        $s = [
            'id_session' => 'chk_' . bin2hex(random_bytes(10)),
            'kind' => 'checkout',
            'owner' => $this->caller->owner,
            'id_cart' => (int) $cart->id,
            'status' => 'incomplete',
            'payload' => ['lines' => $lines, 'discount_codes' => []],
            'expires_at' => date('Y-m-d H:i:s', time() + UcpBridgeCart::TTL),
        ];
        if ($this->caller->idUser) {
            $this->buyer->attachUser($s, $cart);
        }
        if (!isset($body['discounts'])) {
            $body['discounts'] = ['codes' => []];
        }
        $msgs = $this->applyUpdate($s, $cart, $body, false);
        $this->store->save($s);

        return [201, $this->view->checkoutDoc($s, $msgs)];
    }

    public function updateCheckout(array $s, array $body)
    {
        if (UcpBridgeSessionStore::isTerminal($s['status'])) {
            return [200, $this->view->checkoutDoc($s, [UcpBridgeMessage::error('not_modifiable', 'Session ' . UcpBridgeSessionStore::exposedStatus($s['status']) . ' : modification impossible', 'unrecoverable')])];
        }
        // Réservation AVANT toute modification du panier PrestaShop (même statut que celui lu : une écriture concurrente
        // fait échouer la réservation). Une session réservée (finalisation, autre mise à jour, rapprochement) est refusée
        // en 409 avec son état réel : jamais de totaux d'une mise à jour non enregistrée.
        $prev = $s['status'];
        if (UcpBridgeSessionStore::isBusy($prev) || !$this->store->reserve($s['id_session'], [$prev], 'updating')) {
            $cur = $this->store->load($s['id_session'], 'checkout') ?: $s;
            if (UcpBridgeSessionStore::isTerminal($cur['status'])) {
                return [200, $this->view->checkoutDoc($cur, [UcpBridgeMessage::error('not_modifiable', 'Session ' . UcpBridgeSessionStore::exposedStatus($cur['status']) . ' : modification impossible', 'unrecoverable')])];
            }

            return [409, $this->view->checkoutDoc($cur, [UcpBridgeMessage::error('not_modifiable', 'Session réservée (finalisation ou mise à jour en cours) : modification refusée', 'recoverable')])];
        }
        $s = $this->store->load($s['id_session'], 'checkout');
        $s['status'] = $prev;
        try {
            $cart = new Cart((int) $s['id_cart']);
            $msgs = $this->applyUpdate($s, $cart, $body, true);
        } catch (Throwable $e) {
            $s['status'] = $prev;
            $this->store->save($s, ['updating']);
            throw $e;
        }
        $this->store->save($s, ['updating']);

        return [200, $this->view->checkoutDoc($s, $msgs)];
    }

    /** Applique line_items, buyer et fulfillment. Retourne les messages d'erreur de saisie. */
    private function applyUpdate(array &$s, Cart $cart, array $body, $replaceLines)
    {
        $msgs = [];
        $this->lines->bindContext($cart);
        if ($replaceLines && isset($body['line_items'])) {
            list($lines, $errors) = $this->lines->resolveLines($body['line_items']);
            if ($errors) {
                $msgs = array_merge($msgs, $errors);
            } else {
                $this->lines->fillCart($cart, $lines);
                $s['payload']['lines'] = $lines;
            }
        }
        if (isset($body['buyer']['consent'])) {
            // buyer_consent : seuls les booléens explicites sont retenus, jamais de valeur présumée.
            $s['payload']['consent'] = UcpBridgeConsent::normalize($body['buyer']['consent']);
        }
        if (isset($body['buyer']) && is_array($body['buyer']) && !empty($s['payload']['user_customer'])) {
            // Client authentifié : l'identité vient du compte PrestaShop, seul le téléphone est repris.
            if (!empty($body['buyer']['phone_number'])) {
                $s['payload']['buyer']['phone_number'] = (string) $body['buyer']['phone_number'];
            }
        } elseif (isset($body['buyer']) && is_array($body['buyer'])) {
            $b = $body['buyer'];
            if (!empty($b['email']) && !Validate::isEmail($b['email'])) {
                $msgs[] = UcpBridgeMessage::error('invalid', 'Adresse e-mail invalide', 'recoverable', '$.buyer.email');
                unset($b['email']);
            }
            $s['payload']['buyer'] = array_merge($s['payload']['buyer'] ?? [], array_intersect_key($b, array_flip(['email', 'first_name', 'last_name', 'phone_number'])));
            $this->buyer->syncCustomer($s, $cart);
        }
        $method = $body['fulfillment']['methods'][0] ?? null;
        if (is_array($method)) {
            $dests = $method['destinations'] ?? null;
            if (is_array($dests) && $dests) {
                $selected = $method['selected_destination_id'] ?? null;
                $dest = $dests[0];
                foreach ($dests as $d) {
                    if ($selected && ($d['id'] ?? null) === $selected) {
                        $dest = $d;
                    }
                }
                $dest['id'] = $dest['id'] ?? 'dest_1';
                // Adresse du carnet renvoyée telle quelle par l'agent : rien à changer.
                $unchanged = !empty($s['payload']['address_saved']) && $dest['id'] === ($s['payload']['destination']['id'] ?? null);
                $err = $unchanged ? null : $this->fulfillment->applyDestination($s, $cart, $dest);
                if ($err) {
                    $msgs[] = $err;
                }
            }
            $opt = $method['groups'][0]['selected_option_id'] ?? null;
            if ($opt) {
                $err = $this->fulfillment->applyCarrier($s, $cart, (string) $opt);
                if ($err) {
                    $msgs[] = $err;
                }
            }
        }
        // Codes promo en dernier : les règles peuvent dépendre du pays et du transporteur.
        $codes = isset($body['discounts']) ? ($body['discounts']['codes'] ?? []) : null;
        $msgs = array_merge($msgs, (new UcpBridgeDiscount($this->context))->sync($s, $cart, $codes, !empty($s['payload']['id_carrier'])));
        $s['status'] = $this->status->computeStatus($s, $cart);

        return $msgs;
    }

    /** Acceptation des CGV sur la page du module : date, version, IP ; la session redevient finalisable. */
    public function acceptTerms(array $s, $ip, $userAgent)
    {
        $t = $this->module->termsInfo();
        $s['payload']['terms_acceptance'] = [
            'accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => $t['version'],
            'id_cms' => $t['id'],
            'ip' => (string) $ip,
            'user_agent' => substr((string) $userAgent, 0, 255),
        ];
        $cart = new Cart((int) $s['id_cart']);
        $this->lines->bindContext($cart);
        $s['status'] = $this->status->computeStatus($s, $cart);
        $this->store->save($s);

        return $s;
    }

    public function cancelCheckout(array $s)
    {
        if (!in_array($s['status'], UcpBridgeSessionStore::LOCKED, true)) {
            $s['status'] = 'canceled';
            if (!$this->store->save($s)) {
                $s = $this->store->load($s['id_session'], 'checkout');
            }
        }
        $busy = 'completed' === $s['status'] || UcpBridgeSessionStore::isBusy($s['status']);

        return [200, $this->view->checkoutDoc($s, $busy ? [UcpBridgeMessage::error('not_modifiable', 'completed' === $s['status'] ? 'Commande déjà passée'
            : ('updating' === $s['status'] ? 'Mise à jour en cours' : 'Finalisation en cours'), 'unrecoverable')] : [])];
    }
}
