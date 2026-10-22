<?php
/**
 * Document UCP d'une commande passée par l'API (get_order) : lignes, livraison et totaux relus sur la commande
 * PrestaShop, accessible seulement à l'appelant qui l'a passée et par son identifiant opaque.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeOrderView
{
    /** @var Context */
    private $context;
    /** @var UcpBridgeCaller */
    private $caller;
    /** @var UcpBridgeSessionStore */
    private $store;
    /** @var UcpBridgeCheckoutView */
    private $view;

    public function __construct(Context $context, UcpBridgeCaller $caller, UcpBridgeSessionStore $store, UcpBridgeCheckoutView $view)
    {
        $this->context = $context;
        $this->caller = $caller;
        $this->store = $store;
        $this->view = $view;
    }

    /** @return array [code HTTP, document] */
    public function orderDoc($ref)
    {
        $s = $this->store->loadByOrderRef($ref);
        $caps = ['dev.ucp.shopping.order'];
        if (!$s || !$this->caller->owns($s)) {
            return [404, UcpBridgeMessage::errorDoc([UcpBridgeMessage::error('not_found', 'Commande introuvable', 'unrecoverable')], $caps, Ucpbridge::UCP_VERSION, $this->context->link->getPageLink('index', true))];
        }
        $o = new Order((int) $s['id_order']);
        $lines = [];
        $byKey = [];
        foreach ($s['payload']['lines'] as $l) {
            $byKey[$l['id_product'] . '-' . $l['id_product_attribute']] = $l;
        }
        foreach ($o->getOrderDetailList() as $d) {
            $key = (int) $d['product_id'] . '-' . (int) $d['product_attribute_id'];
            $tot = UcpBridgeCartLines::cents($d['total_price_tax_incl']);
            $lines[] = [
                'id' => $byKey[$key]['id'] ?? ('li_' . $d['id_order_detail']),
                'item' => [
                    'id' => $byKey[$key]['requested_id'] ?? $key,
                    'title' => $d['product_name'],
                    'price' => UcpBridgeCartLines::cents($d['unit_price_tax_incl']),
                    'variant_id' => $key,
                    'sku' => $d['product_reference'],
                ],
                'quantity' => ['total' => (int) $d['product_quantity'], 'fulfilled' => (int) $o->hasBeenShipped() ? (int) $d['product_quantity'] : 0],
                'totals' => [['type' => 'subtotal', 'amount' => $tot], ['type' => 'total', 'amount' => $tot]],
                'status' => $o->hasBeenDelivered() ? 'fulfilled' : 'processing',
            ];
        }
        $a = new Address((int) $o->id_address_delivery);
        $carrier = new Carrier((int) $o->id_carrier, (int) $this->context->language->id);
        $state = new OrderState((int) $o->current_state, (int) $this->context->language->id);
        $totals = UcpBridgeTotals::order(UcpBridgeCartLines::cents($o->total_products), UcpBridgeCartLines::cents($o->total_shipping_tax_excl),
            UcpBridgeCartLines::cents($o->total_discounts_tax_excl), UcpBridgeCartLines::cents($o->total_paid_tax_incl));

        return [200, [
            'ucp' => UcpBridgeMessage::envelope(['dev.ucp.shopping.order'], Ucpbridge::UCP_VERSION),
            'id' => (string) $s['order_ref'],
            'reference' => $o->reference,
            'checkout_id' => $s['id_session'],
            'permalink_url' => $this->view->orderPermalink((int) $o->id),
            'currency' => (new Currency((int) $o->id_currency))->iso_code,
            'status_label' => $state->name,
            'line_items' => $lines,
            'fulfillment' => ['expectations' => [[
                'id' => 'exp_1',
                'line_items' => array_map(function ($l) {
                    return ['id' => $l['id'], 'quantity' => $l['quantity']['total']];
                }, $lines),
                'method_type' => 'shipping',
                'destination' => [
                    'first_name' => $a->firstname, 'last_name' => $a->lastname,
                    'street_address' => $a->address1, 'address_locality' => $a->city,
                    'postal_code' => $a->postcode, 'address_country' => Country::getIsoById((int) $a->id_country),
                ],
                'description' => (string) $carrier->name,
            ]], 'events' => []],
            'adjustments' => [],
            'totals' => $totals,
        ]];
    }
}
