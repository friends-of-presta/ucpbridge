<?php
/**
 * Statut d'une session de caisse : ce qui manque pour finaliser (e-mail, adresse, transporteur, stock), relecture des
 * CGV exigée, statut UCP qui en découle.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCheckoutStatus
{
    /** @var Ucpbridge */
    private $module;
    /** @var UcpBridgeCatalog */
    private $catalog;
    /** @var UcpBridgeFulfillment */
    private $fulfillment;

    public function __construct(Ucpbridge $module, UcpBridgeCatalog $catalog, UcpBridgeFulfillment $fulfillment)
    {
        $this->module = $module;
        $this->catalog = $catalog;
        $this->fulfillment = $fulfillment;
    }

    public function missing(array $s, Cart $cart)
    {
        $m = [];
        if (empty($s['payload']['buyer']['email'])) {
            $m[] = UcpBridgeMessage::error('missing', UcpBridgeI18n::api('Buyer e-mail required'), 'recoverable', '$.buyer.email');
        }
        if (empty($s['payload']['id_address'])) {
            $m[] = UcpBridgeMessage::error('missing', UcpBridgeI18n::api('Shipping address required'), 'recoverable', '$.fulfillment.methods[0].destinations');
        } elseif (empty($s['payload']['id_carrier'])) {
            $m[] = $this->fulfillment->carrierOptions($cart)
                ? UcpBridgeMessage::error('missing', UcpBridgeI18n::api('Choose a shipping option'), 'recoverable', '$.fulfillment.methods[0].groups[0].selected_option_id')
                : UcpBridgeMessage::error('address_undeliverable', UcpBridgeI18n::api('No carrier delivers this address'), 'recoverable', '$.fulfillment.methods[0].destinations');
        }
        foreach ($s['payload']['lines'] as $i => $l) {
            if (!$this->catalog->canOrder($l['id_product'], $l['id_product_attribute'], $l['quantity'])) {
                $m[] = UcpBridgeMessage::error('out_of_stock', UcpBridgeI18n::api('Insufficient stock for this variant'), 'recoverable', '$.line_items[' . $i . ']');
            }
        }

        return $m;
    }

    public function computeStatus(array $s, Cart $cart)
    {
        if (in_array($s['status'], UcpBridgeSessionStore::LOCKED, true)
            || ('requires_escalation' === $s['status'] && !empty($s['payload']['escalation_url']))) {
            return $s['status'];
        }
        if ($this->missing($s, $cart)) {
            return 'incomplete';
        }

        return $this->needsTermsReview($s) ? 'requires_escalation' : 'ready_for_complete';
    }

    /** Option « relecture des CGV chez le marchand » active et CGV pas encore acceptées. */
    public function needsTermsReview(array $s)
    {
        return $this->module->termsReviewRequired() && empty($s['payload']['terms_acceptance']);
    }
}
