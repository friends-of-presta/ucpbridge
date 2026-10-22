<?php
/**
 * Document UCP d'une session de caisse (checkout) : lignes, totaux, remises, livraison, messages (manques, mentions
 * légales, états terminaux), lien de reprise ou de relecture des CGV, commande créée.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCheckoutView
{
    /** @var Context */
    private $context;
    /** @var Ucpbridge */
    private $module;
    /** @var UcpBridgeCartLines */
    private $lines;
    /** @var UcpBridgeFulfillment */
    private $fulfillment;
    /** @var UcpBridgeCheckoutStatus */
    private $status;

    public function __construct(Context $context, Ucpbridge $module, UcpBridgeCartLines $lines, UcpBridgeFulfillment $fulfillment,
        UcpBridgeCheckoutStatus $status)
    {
        $this->context = $context;
        $this->module = $module;
        $this->lines = $lines;
        $this->fulfillment = $fulfillment;
        $this->status = $status;
    }

    public function checkoutEnvelope()
    {
        return UcpBridgeMessage::envelope(['dev.ucp.shopping.checkout', 'dev.ucp.shopping.fulfillment', 'dev.ucp.shopping.discount'],
            Ucpbridge::UCP_VERSION, UcpBridgePaymentRegistry::get()->handlers());
    }

    public function checkoutDoc(array $s, array $extraMsgs = [])
    {
        $cart = new Cart((int) $s['id_cart']);
        $this->lines->bindContext($cart);
        $lines = $this->lines->renderLines($cart, $s['payload']['lines']);
        $liIds = array_column($lines, 'id');
        $doc = [
            'ucp' => $this->checkoutEnvelope(),
            'id' => $s['id_session'],
            'status' => UcpBridgeSessionStore::exposedStatus($s['status']),
            'currency' => $this->context->currency->iso_code,
            'line_items' => $lines,
            'totals' => $this->checkoutTotals($s, $cart),
            'discounts' => (new UcpBridgeDiscount($this->context))->render($s, $cart, false, !empty($s['payload']['id_carrier'])),
            'links' => $this->links(),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($s['expires_at'])),
        ];
        if (!empty($s['payload']['buyer'])) {
            $doc['buyer'] = $s['payload']['buyer'];
            if (!empty($s['payload']['consent'])) {
                $doc['buyer']['consent'] = $s['payload']['consent'];
            }
        }
        $method = ['id' => 'shipping_1', 'type' => 'shipping', 'line_item_ids' => $liIds, 'destinations' => []];
        if (!empty($s['payload']['destination'])) {
            $method['destinations'][] = $s['payload']['destination'];
            $method['selected_destination_id'] = $s['payload']['destination']['id'];
            $group = ['id' => 'package_1', 'line_item_ids' => $liIds, 'options' => array_values($this->fulfillment->carrierOptions($cart))];
            if (!empty($s['payload']['id_carrier'])) {
                $group['selected_option_id'] = 'carrier_' . (int) $s['payload']['id_carrier'];
            }
            $method['groups'] = [$group];
        }
        $doc['fulfillment'] = ['methods' => [$method]];
        $messages = array_merge($extraMsgs, $this->terminalMessages($s));
        if (!UcpBridgeSessionStore::isTerminal($s['status']) && !UcpBridgeSessionStore::isBusy($s['status'])) {
            $messages = array_merge($messages, $this->status->missing($s, $cart));
            if (!$extraMsgs && !empty($s['payload']['discount_messages'])) {
                $messages = array_merge($messages, $s['payload']['discount_messages']);
            }
        }
        $review = 'requires_escalation' === $s['status'] && empty($s['payload']['escalation_url']);
        if ($review) {
            $messages[] = UcpBridgeMessage::error('terms_review_required', UcpBridgeI18n::api('The buyer must review and accept the terms of sale on the shop before ordering'), 'requires_buyer_review', '$.links[0]');
        }
        if ('canceled' !== UcpBridgeSessionStore::exposedStatus($s['status'])) {
            $messages = array_merge($messages, $this->disclosures($s['payload']['destination']['address_country'] ?? null));
        }
        if ('complete_in_progress' === $s['status'] && !empty($s['payload']['charge_unknown'])) {
            $messages[] = UcpBridgeMessage::error('payment_verification', UcpBridgeI18n::api('Payment result unknown: do not retry with another payment method, the merchant checks with the payment provider and refunds if a charge took place'), 'requires_buyer_input');
        }
        if ('requires_escalation' === $s['status'] && !empty($s['payload']['escalation_url'])) {
            $messages[] = UcpBridgeMessage::error('requires_3ds', UcpBridgeI18n::api('Strong authentication (3-D Secure) required: the buyer must finish the payment on the shop'), 'requires_buyer_input');
        }
        if ($messages) {
            $doc['messages'] = array_values($messages);
        }
        if ('completed' === $s['status'] && !empty($s['id_order'])) {
            $doc['order'] = ['id' => (string) $s['order_ref'], 'permalink_url' => $this->orderPermalink((int) $s['id_order'])];
            if (!empty($s['payload']['transaction_id'])) {
                $doc['payment'] = ['transaction_id' => $s['payload']['transaction_id'], 'label' => $s['payload']['payment_label'] ?? ''];
            }
        } elseif (!UcpBridgeSessionStore::isTerminal($s['status']) && !UcpBridgeSessionStore::isBusy($s['status'])) {
            // Relecture des CGV : page signée du module. Sinon reprise par un humain : le même panier, dans le navigateur.
            $doc['continue_url'] = $review
                ? $this->module->termsReviewUrl($s['id_session'], strtotime($s['expires_at']) - time())
                : $this->module->buildContinueUrl((int) $s['id_cart']);
        }

        return $doc;
    }

    /** Totaux cohérents (somme des lignes = total) : sous-total HT, réductions HT, livraison HT, TVA, total TTC. */
    public function checkoutTotals(array $s, Cart $cart)
    {
        $hasCarrier = !empty($s['payload']['id_carrier']);
        $mode = $hasCarrier ? Cart::BOTH : Cart::BOTH_WITHOUT_SHIPPING;
        $country = $cart->id_address_delivery ? Country::getIsoById((int) (new Address((int) $cart->id_address_delivery))->id_country) : null;

        return UcpBridgeTotals::checkout(
            UcpBridgeCartLines::cents($cart->getOrderTotal(false, Cart::ONLY_PRODUCTS)),
            $hasCarrier ? UcpBridgeCartLines::cents($cart->getOrderTotal(false, Cart::ONLY_SHIPPING)) : null,
            UcpBridgeCartLines::cents($cart->getOrderTotal(false, $mode)),
            UcpBridgeCartLines::cents($cart->getOrderTotal(true, $mode)),
            $country
        );
    }

    /** Messages des états terminaux internes : lien de reprise ouvert, commande impossible après débit. */
    public function terminalMessages(array $s)
    {
        if ('handed_off' === $s['status']) {
            return [UcpBridgeMessage::error('checkout_handed_off', UcpBridgeI18n::api('The buyer took this cart over on the shop (continue link opened): the session can no longer be completed through the API'), 'unrecoverable')];
        }
        if ('failed' !== $s['status']) {
            return [];
        }
        $f = $s['payload']['failure'] ?? [];
        $m = [UcpBridgeMessage::error('order_failed', UcpBridgeI18n::api('The payment was accepted but the order could not be created'), 'unrecoverable')];
        if (!empty($f['unknown'])) {
            $m = [UcpBridgeMessage::error('order_failed', UcpBridgeI18n::api('Completion was interrupted before the order was created'), 'unrecoverable'),
                UcpBridgeMessage::error('payment_verification', UcpBridgeI18n::api('Uncertain charge: the merchant checks with the payment provider and refunds if a charge took place'), 'requires_buyer_input')];
        } elseif (!empty($f['refunded'])) {
            $m[] = UcpBridgeMessage::info('payment_refunded', UcpBridgeI18n::api('The payment was refunded automatically'));
        } else {
            $m[] = UcpBridgeMessage::error('refund_pending', UcpBridgeI18n::api('Automatic refund impossible: the merchant has been alerted and must refund'), 'requires_buyer_input');
        }

        return $m;
    }

    /** Mentions obligatoires (presentation « disclosure ») pour le pays de livraison. */
    public function disclosures($iso)
    {
        $d = $this->module->withdrawalDisclosure(is_string($iso) ? $iso : null);

        return $d ? [$d] : [];
    }

    /** Liens légaux construits depuis les pages CMS configurées (titres en langue de la boutique). */
    public function links()
    {
        return $this->module->legalLinks();
    }

    public function orderPermalink($idOrder)
    {
        $o = new Order((int) $idOrder);

        return $this->context->link->getPageLink('guest-tracking', true, null, ['order_reference' => $o->reference]);
    }
}
