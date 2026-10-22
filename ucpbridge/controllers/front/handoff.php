<?php
/**
 * Transfert de panier : le continue_url signé (HMAC, 24 h) atterrit ici. On rattache le panier
 * PrestaShop créé par l'agent à la session du visiteur (cookie id_cart), puis on l'envoie sur
 * la page panier de la boutique.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpbridgeHandoffModuleFrontController extends ModuleFrontController
{
    /** @var Ucpbridge */
    public $module;

    public function postProcess()
    {
        $idCart = (int) Tools::getValue('cart');
        if (!$idCart || !$this->module->checkSigned('handoff', ['cart'])) {
            $this->refuse(UcpBridgeI18n::shop('Invalid or expired cart link.'));
        }
        $cart = new Cart($idCart);
        if (!Validate::isLoadedObject($cart) || (int) $cart->id_shop !== (int) $this->context->shop->id || $cart->orderExists()) {
            $this->refuse(UcpBridgeI18n::shop('This cart is no longer available.'));
        }
        // Un panier rattaché à un client connu ne se rouvre que pour ce client.
        if ($cart->id_customer) {
            $owner = new Customer((int) $cart->id_customer);
            if (!$owner->is_guest && (int) $this->context->customer->id !== (int) $owner->id) {
                $this->refuse(UcpBridgeI18n::shop('Log in to find this cart again.'));
            }
        }

        // Le panier passe à l'humain : toute session UCP sur ce panier (caisse ou panier transférable) sort du circuit
        // API (statut terminal handed_off, exposé « canceled » ; l'API refuse ensuite toute modification), avant tout
        // changement du panier. Une finalisation déjà engagée par l'agent (complete_in_progress) a la priorité : le lien
        // est alors refusé ; une mise à jour en cours (updating, réservée avant de toucher au panier) aussi.
        $t = '`' . _DB_PREFIX_ . 'ucpbridge_session`';
        Db::getInstance()->execute('UPDATE ' . $t . ' SET status = \'handed_off\', date_upd = \'' . pSQL(date('Y-m-d H:i:s')) . '\'
            WHERE id_cart = ' . (int) $cart->id . ' AND ((kind = \'checkout\' AND status IN (\'incomplete\', \'ready_for_complete\', \'requires_escalation\'))
            OR (kind = \'cart\' AND status = \'active\'))');
        $busy = (string) Db::getInstance()->getValue('SELECT status FROM ' . $t . ' WHERE id_cart = ' . (int) $cart->id
            . ' AND status IN (\'complete_in_progress\', \'reconciling\', \'completed\', \'updating\') ORDER BY status = \'updating\' DESC');
        if ('updating' === $busy) {
            $this->refuse(UcpBridgeI18n::shop('Your assistant is modifying this cart: try again in a few seconds.'));
        } elseif ('' !== $busy) {
            $this->refuse(UcpBridgeI18n::shop('This order is being completed by your assistant.'));
        }

        $cart->id_guest = (int) $this->context->cookie->id_guest;
        $cart->id_lang = (int) $this->context->language->id;
        $cart->update();

        $this->context->cookie->id_cart = (int) $cart->id;
        if (!$this->context->customer->isLogged() && $cart->id_customer) {
            // Panier d'un invité : on le détache du client invité pour que le visiteur termine librement.
            $cart->id_customer = 0;
            $cart->update();
        }
        $this->context->cookie->write();
        $this->context->cart = $cart;

        Tools::redirect($this->context->link->getPageLink('cart', null, null, ['action' => 'show']));
    }

    private function refuse($message)
    {
        $this->errors[] = $message;
        $this->redirectWithNotifications($this->context->link->getPageLink('cart', null, null, ['action' => 'show']));
    }
}
