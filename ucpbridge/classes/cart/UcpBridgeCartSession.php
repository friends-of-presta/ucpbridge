<?php
/**
 * Panier UCP transférable (dev.ucp.shopping.cart) : création, lecture, remplacement du contenu, annulation, et fiche
 * produit (get_product). Chaque panier UCP s'appuie sur un vrai panier PrestaShop et porte un lien de reprise signé.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCartSession
{
    /** @var Context */
    private $context;
    /** @var Ucpbridge */
    private $module;
    /** @var UcpBridgeCaller */
    private $caller;
    /** @var UcpBridgeSessionStore */
    private $store;
    /** @var UcpBridgeCatalog */
    private $catalog;
    /** @var UcpBridgeCartLines */
    private $lines;
    /** @var UcpBridgeCheckoutView */
    private $view;

    public function __construct(Context $context, Ucpbridge $module, UcpBridgeCaller $caller, UcpBridgeSessionStore $store,
        UcpBridgeCatalog $catalog, UcpBridgeCartLines $lines, UcpBridgeCheckoutView $view)
    {
        $this->context = $context;
        $this->module = $module;
        $this->caller = $caller;
        $this->store = $store;
        $this->catalog = $catalog;
        $this->lines = $lines;
        $this->view = $view;
    }

    private function errorDoc(array $messages, array $capabilities)
    {
        return UcpBridgeMessage::errorDoc($messages, $capabilities, Ucpbridge::UCP_VERSION, $this->context->link->getPageLink('index', true));
    }

    private function cartEnvelope()
    {
        return UcpBridgeMessage::envelope(['dev.ucp.shopping.cart', 'dev.ucp.shopping.checkout', 'dev.ucp.shopping.discount'],
            Ucpbridge::UCP_VERSION);
    }

    public function createCart(array $body)
    {
        list($lines, $errors) = $this->lines->resolveLines($body['line_items'] ?? null);
        if ($errors) {
            return [200, $this->errorDoc($errors, ['dev.ucp.shopping.cart'])];
        }
        $cart = $this->lines->newPsCart();
        if (!$this->lines->fillCart($cart, $lines)) {
            return [200, $this->errorDoc([UcpBridgeMessage::error('out_of_stock', 'Impossible d\'ajouter les articles au panier', 'unrecoverable')], ['dev.ucp.shopping.cart'])];
        }
        if ($this->caller->idUser) {
            $cart->id_customer = $this->caller->idUser;
            $cart->secure_key = (new Customer($this->caller->idUser))->secure_key;
            $cart->update();
        }
        $s = [
            'id_session' => 'cart_' . bin2hex(random_bytes(10)),
            'kind' => 'cart',
            'owner' => $this->caller->owner,
            'id_cart' => (int) $cart->id,
            'status' => 'active',
            'payload' => ['lines' => $lines, 'context' => $body['context'] ?? null, 'buyer' => $body['buyer'] ?? null,
                'user_customer' => $this->caller->idUser],
            'expires_at' => date('Y-m-d H:i:s', time() + UcpBridgeCart::TTL),
        ];
        $this->lines->bindContext($cart);
        $msgs = (new UcpBridgeDiscount($this->context))->sync($s, $cart, $body['discounts']['codes'] ?? [], false);
        $this->store->save($s);

        return [201, $this->cartDoc($s, $msgs)];
    }

    /** Détail d'un produit (get_product) : produit et toutes ses variantes. */
    public function productDoc($id)
    {
        $idProduct = $this->catalog->productIdFor($id);
        if (!$idProduct) {
            return $this->errorDoc([UcpBridgeMessage::error('not_found', 'Produit introuvable : ' . $id, 'unrecoverable', '$.id')], ['dev.ucp.shopping.catalog.search']);
        }

        return [
            'ucp' => UcpBridgeMessage::envelope(['dev.ucp.shopping.catalog.search'], Ucpbridge::UCP_VERSION),
            'product' => $this->catalog->productView($idProduct),
        ];
    }

    public function cartDoc(array $s, $msgs = null)
    {
        if ('handed_off' === $s['status']) {
            return $this->errorDoc([self::handedOffMsg()], ['dev.ucp.shopping.cart']);
        }
        if ('active' !== $s['status'] && 'updating' !== $s['status']) {
            return $this->errorDoc([UcpBridgeMessage::error('not_found', 'Panier expiré ou annulé', 'unrecoverable')], ['dev.ucp.shopping.cart']);
        }
        $cart = new Cart((int) $s['id_cart']);
        $this->lines->bindContext($cart);
        $lines = $this->lines->renderLines($cart, $s['payload']['lines']);
        $totals = UcpBridgeTotals::cart(UcpBridgeCartLines::cents($cart->getOrderTotal(true, Cart::ONLY_PRODUCTS)),
            UcpBridgeCartLines::cents($cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING)));
        $exp = strtotime($s['expires_at']);
        $doc = [
            'ucp' => $this->cartEnvelope(),
            'id' => $s['id_session'],
            'line_items' => $lines,
            'currency' => $this->context->currency->iso_code,
            'totals' => $totals,
            'discounts' => (new UcpBridgeDiscount($this->context))->render($s, $cart, true, false),
            // Lien signé (HMAC, 24 h) : rattache CE panier PrestaShop à la session du visiteur.
            'continue_url' => $this->module->buildContinueUrl((int) $s['id_cart'], max(60, $exp - time())),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp),
            'links' => $this->view->links(),
        ];
        if (!empty($s['payload']['buyer'])) {
            $doc['buyer'] = $s['payload']['buyer'];
        }
        $msgs = null === $msgs ? ($s['payload']['discount_messages'] ?? []) : $msgs;
        $msgs = array_merge(array_values($msgs), $this->view->disclosures($s['payload']['context']['address_country'] ?? null));
        if ($msgs) {
            $doc['messages'] = array_values($msgs);
        }

        return $doc;
    }

    private static function handedOffMsg()
    {
        return UcpBridgeMessage::error('checkout_handed_off', 'L\'acheteur a repris ce panier sur la boutique (lien de reprise ouvert) : '
            . 'il ne peut plus être modifié par l\'API', 'unrecoverable');
    }

    public function updateCart(array $s, array $body)
    {
        if ('handed_off' === $s['status']) {
            return [409, $this->cartDoc($s)];
        }
        if ('active' !== $s['status'] && 'updating' !== $s['status']) {
            return [200, $this->cartDoc($s)];
        }
        list($lines, $errors) = $this->lines->resolveLines($body['line_items'] ?? null);
        if ($errors) {
            return [200, $this->errorDoc($errors, ['dev.ucp.shopping.cart'])];
        }
        // Réservation AVANT de toucher au panier PrestaShop : le lien de reprise (handoff) et une autre mise à jour
        // ne peuvent plus s'intercaler. Refus 409 si la session est déjà réservée ou sortie de l'API.
        if (!$this->store->reserve($s['id_session'], ['active'], 'updating')) {
            $cur = $this->store->load($s['id_session'], 'cart') ?: $s;
            if ('handed_off' === $cur['status']) {
                return [409, $this->cartDoc($cur)];
            }

            return [409, $this->errorDoc([UcpBridgeMessage::error('not_modifiable', 'Panier en cours de modification : réessayer', 'recoverable')], ['dev.ucp.shopping.cart'])];
        }
        $s = $this->store->load($s['id_session'], 'cart');
        $s['status'] = 'active';
        try {
            $cart = new Cart((int) $s['id_cart']);
            if ($cart->orderExists()) {
                $this->store->save($s, ['updating']);

                return [200, $this->errorDoc([UcpBridgeMessage::error('not_found', 'Ce panier a déjà donné lieu à une commande', 'unrecoverable')], ['dev.ucp.shopping.cart'])];
            }
            $this->lines->fillCart($cart, $lines);
            $s['payload']['lines'] = $lines;
            if (isset($body['buyer'])) {
                $s['payload']['buyer'] = $body['buyer'];
            }
            $this->lines->bindContext($cart);
            $msgs = (new UcpBridgeDiscount($this->context))->sync($s, $cart, $body['discounts']['codes'] ?? null, false);
        } catch (Throwable $e) {
            $this->store->save($s, ['updating']);
            throw $e;
        }
        $this->store->save($s, ['updating']);

        return [200, $this->cartDoc($s, $msgs)];
    }

    public function cancelCart(array $s)
    {
        $s['status'] = 'canceled';
        $this->store->save($s);

        return [200, $this->errorDoc([UcpBridgeMessage::error('canceled', 'Panier annulé', 'unrecoverable')], ['dev.ucp.shopping.cart'])];
    }
}
