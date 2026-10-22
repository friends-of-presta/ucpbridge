<?php
/**
 * Livraison d'une session de caisse : adresse de destination (validée et enregistrée comme adresse PrestaShop),
 * transporteurs réels de la boutique pour cette adresse, choix de l'option de livraison.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeFulfillment
{
    /** @var Context */
    private $context;
    /** @var UcpBridgeCartLines */
    private $lines;

    public function __construct(Context $context, UcpBridgeCartLines $lines)
    {
        $this->context = $context;
        $this->lines = $lines;
    }

    public function applyDestination(array &$s, Cart $cart, array $d)
    {
        foreach (['street_address', 'address_locality', 'postal_code', 'address_country'] as $f) {
            if (empty($d[$f])) {
                return UcpBridgeMessage::error('missing', UcpBridgeI18n::api('Missing address field: %field%', ['%field%' => $f]), 'recoverable', '$.fulfillment.methods[0].destinations[0].' . $f);
            }
        }
        $idCountry = (int) Country::getByIso(Tools::strtoupper((string) $d['address_country']), true);
        if (!$idCountry) {
            return UcpBridgeMessage::error('address_undeliverable', UcpBridgeI18n::api('Country not delivered by the shop: %country%', ['%country%' => $d['address_country']]), 'recoverable', '$.fulfillment.methods[0].destinations[0].address_country');
        }
        $country = new Country($idCountry);
        if (!$country->checkZipCode((string) $d['postal_code'])) {
            return UcpBridgeMessage::error('invalid', UcpBridgeI18n::api('Invalid postal code for %country%', ['%country%' => $country->iso_code]), 'recoverable', '$.fulfillment.methods[0].destinations[0].postal_code');
        }
        // Adresse du carnet client : jamais modifiée, une nouvelle adresse est créée à côté.
        $reuse = !empty($s['payload']['id_address']) && empty($s['payload']['address_saved']);
        $a = $reuse ? new Address((int) $s['payload']['id_address']) : new Address();
        $buyer = $s['payload']['buyer'] ?? [];
        $a->id_customer = (int) $cart->id_customer;
        $a->alias = 'UCP';
        $a->firstname = UcpBridgeBuyer::cleanName($d['first_name'] ?? ($buyer['first_name'] ?? 'Client'));
        $a->lastname = UcpBridgeBuyer::cleanName($d['last_name'] ?? ($buyer['last_name'] ?? 'UCP'));
        $a->address1 = Tools::substr((string) $d['street_address'], 0, 128);
        $a->address2 = Tools::substr((string) ($d['extended_address'] ?? ''), 0, 128);
        $a->postcode = (string) $d['postal_code'];
        $a->city = Tools::substr((string) $d['address_locality'], 0, 64);
        $a->id_country = $idCountry;
        $a->id_state = !empty($d['address_region']) ? (int) State::getIdByIso((string) $d['address_region'], $idCountry) : 0;
        $a->phone = isset($buyer['phone_number']) ? (string) $buyer['phone_number'] : '';
        $check = $a->validateFields(false, true);
        if (true !== $check) {
            return UcpBridgeMessage::error('invalid', UcpBridgeI18n::api('Address refused: %reason%', ['%reason%' => strip_tags((string) $check)]), 'recoverable', '$.fulfillment.methods[0].destinations[0]');
        }
        $ok = Validate::isLoadedObject($a) ? $a->update() : $a->add();
        if (!$ok) {
            return UcpBridgeMessage::error('invalid', UcpBridgeI18n::api('Address refused by the shop'), 'recoverable', '$.fulfillment.methods[0].destinations[0]');
        }
        $s['payload']['id_address'] = (int) $a->id;
        $s['payload']['address_saved'] = false;
        $s['payload']['destination'] = $d;
        $cart->id_address_delivery = (int) $a->id;
        $cart->id_address_invoice = (int) $a->id;
        $cart->update();
        Db::getInstance()->update('cart_product', ['id_address_delivery' => (int) $a->id], 'id_cart = ' . (int) $cart->id);
        Cart::resetStaticCache();
        // Changement d'adresse : le transporteur choisi doit rester valable pour la nouvelle zone.
        if (!empty($s['payload']['id_carrier']) && !isset($this->carrierOptions($cart)['carrier_' . $s['payload']['id_carrier']])) {
            unset($s['payload']['id_carrier']);
            $cart->id_carrier = 0;
            $cart->delivery_option = '';
            $cart->update();
        }

        return null;
    }

    /** Transporteurs réels de PrestaShop pour l'adresse du panier, prix TTC. */
    public function carrierOptions(Cart $cart)
    {
        if (!$cart->id_address_delivery) {
            return [];
        }
        $this->lines->bindContext($cart);
        $list = $cart->getDeliveryOptionList(null, true);
        $out = [];
        foreach ($list[(int) $cart->id_address_delivery] ?? [] as $key => $opt) {
            $ids = array_filter(explode(',', (string) $key));
            if (1 !== count($ids)) {
                continue; // options multi-transporteurs : hors périmètre du prototype
            }
            $idCarrier = (int) reset($ids);
            $info = $opt['carrier_list'][$idCarrier] ?? [];
            $carrier = $info['instance'] ?? new Carrier($idCarrier, (int) $this->context->language->id);
            $delay = is_array($carrier->delay) ? ($carrier->delay[(int) $this->context->language->id] ?? '') : (string) $carrier->delay;
            $out['carrier_' . $idCarrier] = [
                'id' => 'carrier_' . $idCarrier,
                'title' => (string) $carrier->name,
                'description' => $delay,
                'totals' => [['type' => 'total', 'amount' => UcpBridgeCartLines::cents($opt['total_price_with_tax']), 'display_text' => 'TTC']],
            ];
        }

        return $out;
    }

    public function applyCarrier(array &$s, Cart $cart, $optionId)
    {
        if (!$cart->id_address_delivery) {
            return UcpBridgeMessage::error('missing', UcpBridgeI18n::api('Choose an address before the carrier'), 'recoverable', '$.fulfillment.methods[0].destinations');
        }
        $opts = $this->carrierOptions($cart);
        if (!isset($opts[$optionId])) {
            return UcpBridgeMessage::error('invalid', UcpBridgeI18n::api('Unknown shipping option: %option%', ['%option%' => $optionId]), 'recoverable', '$.fulfillment.methods[0].groups[0].selected_option_id');
        }
        $idCarrier = (int) substr($optionId, strlen('carrier_'));
        $cart->setDeliveryOption([(int) $cart->id_address_delivery => $idCarrier . ',']);
        $cart->id_carrier = $idCarrier;
        $cart->update();
        Cart::resetStaticCache();
        $s['payload']['id_carrier'] = $idCarrier;

        return null;
    }
}
