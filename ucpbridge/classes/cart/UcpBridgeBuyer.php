<?php
/**
 * Acheteur d'une session de caisse : rattachement du client authentifié (identity linking), création ou mise à jour
 * du client invité, consentements newsletter et partenaires (uniquement sur accord explicite).
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeBuyer
{
    /** @var Context */
    private $context;
    /** @var UcpBridgeCaller */
    private $caller;

    public function __construct(Context $context, UcpBridgeCaller $caller)
    {
        $this->context = $context;
        $this->caller = $caller;
    }

    /** Rattache le panier au vrai client : identité, adresse enregistrée proposée par défaut. */
    public function attachUser(array &$s, Cart $cart)
    {
        $c = new Customer($this->caller->idUser);
        if (!Validate::isLoadedObject($c) || $c->is_guest || !$c->active) {
            return;
        }
        $s['payload']['user_customer'] = (int) $c->id;
        $s['payload']['id_customer'] = (int) $c->id;
        $s['payload']['buyer'] = ['email' => $c->email, 'first_name' => $c->firstname, 'last_name' => $c->lastname];
        $cart->id_customer = (int) $c->id;
        $cart->secure_key = $c->secure_key;
        $this->context->customer = $c;
        $idAddress = (int) Address::getFirstCustomerAddressId((int) $c->id);
        if ($idAddress) {
            $a = new Address($idAddress);
            $s['payload']['id_address'] = $idAddress;
            $s['payload']['address_saved'] = true;
            $s['payload']['destination'] = [
                'id' => 'addr_' . $idAddress,
                'first_name' => $a->firstname, 'last_name' => $a->lastname,
                'street_address' => $a->address1, 'extended_address' => (string) $a->address2,
                'address_locality' => $a->city, 'postal_code' => $a->postcode,
                'address_country' => Country::getIsoById((int) $a->id_country),
            ];
            $cart->id_address_delivery = $idAddress;
            $cart->id_address_invoice = $idAddress;
        }
        $cart->update();
        if ($idAddress) {
            Db::getInstance()->update('cart_product', ['id_address_delivery' => $idAddress], 'id_cart = ' . (int) $cart->id);
        }
        Cart::resetStaticCache();
    }

    public function syncCustomer(array &$s, Cart $cart)
    {
        $b = $s['payload']['buyer'] ?? [];
        if (empty($b['email'])) {
            return;
        }
        $c = !empty($s['payload']['id_customer']) ? new Customer((int) $s['payload']['id_customer']) : new Customer();
        $c->email = $b['email'];
        $c->firstname = self::cleanName($b['first_name'] ?? ($s['payload']['destination']['first_name'] ?? 'Client'));
        $c->lastname = self::cleanName($b['last_name'] ?? ($s['payload']['destination']['last_name'] ?? 'UCP'));
        if (!Validate::isLoadedObject($c)) {
            // Client invité : pas de compte, mot de passe aléatoire jamais communiqué.
            $c->is_guest = 1;
            $c->newsletter = 0; // aucun consentement présumé : seul buyer.consent.marketing = true l'active
            $c->optin = 0;
            $c->passwd = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $c->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
            $c->id_shop = (int) $this->context->shop->id;
            $c->id_lang = (int) $this->context->language->id;
            $c->add();
            $c->updateGroup([(int) Configuration::get('PS_GUEST_GROUP')]);
            $s['payload']['id_customer'] = (int) $c->id;
            // Invité créé par le module : purgé s'il ne commande pas (durée de conservation, UcpBridgePurge).
            UcpBridgePurge::recordGuest((int) $c->id);
        } else {
            $c->update();
        }
        $cart->id_customer = (int) $c->id;
        $cart->secure_key = $c->secure_key;
        $cart->update();
        if (!empty($s['payload']['id_address'])) {
            $a = new Address((int) $s['payload']['id_address']);
            if (Validate::isLoadedObject($a) && !$a->id_customer) {
                $a->id_customer = (int) $c->id;
                $a->update();
            }
        }
        $this->context->customer = $c;
    }

    public static function cleanName($n)
    {
        $n = trim(preg_replace('/[0-9!<>,;?=+()@#"°{}_$%:]/u', '', (string) $n));

        return '' !== $n ? Tools::substr($n, 0, 255) : 'Client';
    }

    /** Options newsletter et partenaires du client PrestaShop, uniquement sur consentement explicite. */
    public function applyConsent($idCustomer, array $consent)
    {
        $data = [];
        $nl = UcpBridgeConsent::newsletter($consent);
        if (null !== $nl) {
            $data['newsletter'] = (int) $nl;
            if ($nl) {
                $data['newsletter_date_add'] = date('Y-m-d H:i:s');
                $data['ip_registration_newsletter'] = pSQL(Tools::getRemoteAddr());
            }
        }
        $optin = UcpBridgeConsent::partnerOptin($consent);
        if (null !== $optin) {
            $data['optin'] = (int) $optin;
        }
        if ($data && $idCustomer) {
            Db::getInstance()->update('customer', $data, 'id_customer = ' . (int) $idCustomer);
        }
    }
}
