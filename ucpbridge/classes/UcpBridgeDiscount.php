<?php
/**
 * Codes promo UCP (dev.ucp.shopping.discount) appliqués avec les règles panier PrestaShop.
 * Le module ne calcule aucune remise lui-même : il pose les CartRule sur le vrai panier
 * et relit ce que PrestaShop en déduit. Les règles automatiques (sans code) sont gérées
 * par CartRule::autoAddToCart / autoRemoveFromCart, comme dans le tunnel classique.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeDiscount
{
    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /** Codes normalisés : majuscules, sans doublon, 20 au plus (garde-fou contre le bourrage). */
    public static function normalizeCodes($codes)
    {
        $out = [];
        foreach ((array) $codes as $c) {
            $c = Tools::strtoupper(trim((string) $c));
            if ('' !== $c && Tools::strlen($c) <= 254 && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return array_slice($out, 0, 20);
    }

    private static function warn($code, $content, $index)
    {
        return ['type' => 'warning', 'code' => $code, 'content' => $content, 'path' => '$.discounts.codes[' . (int) $index . ']'];
    }

    /**
     * Synchronise les règles panier avec les codes demandés.
     *
     * @param array      $s       session UCP (payload modifié : discount_codes, discount_messages)
     * @param Cart       $cart    panier PrestaShop
     * @param array|null $codes   nouveaux codes (remplacement intégral), null = revalider l'existant
     * @param bool       $carrier un transporteur est-il déjà choisi (contrôle des restrictions transporteur)
     *
     * @return array messages de rejet (type warning, codes de la spécification)
     */
    public function sync(array &$s, Cart $cart, $codes, $carrier)
    {
        $this->context->cart = $cart;
        if (!Validate::isLoadedObject($this->context->customer ?? null)) {
            $this->context->customer = $cart->id_customer ? new Customer((int) $cart->id_customer) : new Customer();
        }
        if (null !== $codes) {
            $s['payload']['discount_codes'] = self::normalizeCodes($codes);
        }
        $wanted = $s['payload']['discount_codes'] ?? [];

        // Retire les règles à code que l'agent ne demande plus (sémantique de remplacement).
        foreach ($cart->getCartRules(CartRule::FILTER_ACTION_ALL, false) as $r) {
            if ('' !== (string) $r['code'] && !in_array(Tools::strtoupper($r['code']), $wanted, true)) {
                $cart->removeCartRule((int) $r['id_cart_rule']);
            }
        }
        // Revalidation par PrestaShop (quantités, montant minimum, pays) puis règles automatiques.
        CartRule::autoRemoveFromCart($this->context);
        CartRule::autoAddToCart($this->context);

        $inCart = [];
        foreach ($cart->getCartRules(CartRule::FILTER_ACTION_ALL, false) as $r) {
            $inCart[(int) $r['id_cart_rule']] = true;
        }
        $msgs = [];
        foreach ($wanted as $i => $code) {
            $id = (int) CartRule::getIdByCode($code);
            if (!$id) {
                $msgs[] = self::warn('discount_code_invalid', 'Code promo inconnu : ' . $code, $i);
                continue;
            }
            if (isset($inCart[$id])) {
                continue; // déjà posé et toujours valable
            }
            $rule = new CartRule($id, (int) $this->context->language->id);
            $err = $this->precheck($rule);
            if (!$err) {
                $r = $rule->checkValidity($this->context, false, true, (bool) $carrier);
                if (is_string($r) && '' !== $r) {
                    $err = [$this->mapError($r), $r];
                } elseif (false === $r) {
                    $err = ['discount_code_invalid', 'Code promo non applicable'];
                }
            }
            if ($err) {
                $msgs[] = self::warn($err[0], $code . ' : ' . $err[1], $i);
                continue;
            }
            $cart->addCartRule($id);
            $inCart[$id] = true;
        }
        Cart::resetStaticCache();
        $s['payload']['discount_messages'] = $msgs;

        return $msgs;
    }

    /** Contrôles explicites pour renvoyer le code d'erreur exact de la spécification. */
    private function precheck(CartRule $rule)
    {
        $now = date('Y-m-d H:i:s');
        if (!$rule->active) {
            return ['discount_code_invalid', UcpBridgeI18n::api('code disabled')];
        }
        if ($rule->date_to && '0000-00-00 00:00:00' !== $rule->date_to && $rule->date_to < $now) {
            return ['discount_code_expired', UcpBridgeI18n::api('code expired since %date%', ['%date%' => date('d/m/Y', strtotime($rule->date_to))])];
        }
        if ($rule->date_from && $rule->date_from > $now) {
            return ['discount_code_invalid', UcpBridgeI18n::api('code valid from %date%', ['%date%' => date('d/m/Y', strtotime($rule->date_from))])];
        }
        if ($rule->quantity <= 0) {
            return ['discount_code_invalid', UcpBridgeI18n::api('code used up')];
        }
        if ($rule->id_customer) {
            $c = $this->context->customer;
            if (!$c || !$c->id || $c->is_guest) {
                return ['discount_code_user_not_logged_in', UcpBridgeI18n::api('code reserved for an identified customer (identity linking required)')];
            }
            if ((int) $c->id !== (int) $rule->id_customer) {
                return ['discount_code_user_ineligible', UcpBridgeI18n::api('code reserved for another customer')];
            }
        }

        return null;
    }

    private function mapError($text)
    {
        if (false !== stripos($text, 'combinable') || false !== stripos($text, 'cumulable')) {
            return 'discount_code_combination_disallowed';
        }
        if (false !== stripos($text, 'already in your cart') || false !== stripos($text, 'déjà dans votre panier')) {
            return 'discount_code_already_applied';
        }
        if (false !== stripos($text, 'log in') || false !== stripos($text, 'connecter')) {
            return 'discount_code_user_not_logged_in';
        }

        return 'discount_code_invalid';
    }

    /**
     * Objet « discounts » de la réponse : codes demandés et remises réellement appliquées.
     *
     * @param bool $taxIncl montants TTC (panier) ou HT (caisse, alignés sur les totaux HT)
     * @param bool $carrier transporteur choisi (sinon la livraison offerte est provisoire)
     */
    public function render(array $s, Cart $cart, $taxIncl, $carrier)
    {
        $applied = [];
        $prio = 1;
        foreach ($cart->getCartRules(CartRule::FILTER_ACTION_ALL, false) as $r) {
            $amount = (int) round(((float) ($taxIncl ? $r['value_real'] : $r['value_tax_exc'])) * 100);
            $a = [
                'title' => (string) $r['name'],
                'amount' => max(0, $amount),
                'method' => 'across',
                'priority' => $prio++,
            ];
            if ('' !== (string) $r['code']) {
                $a['code'] = Tools::strtoupper($r['code']);
            } else {
                $a['automatic'] = true;
            }
            if (!empty($r['free_shipping']) && !$carrier) {
                $a['provisional'] = true; // montant connu une fois le transporteur choisi
            }
            $applied[] = $a;
        }
        $out = ['codes' => $s['payload']['discount_codes'] ?? []];
        if ($applied) {
            $out['applied'] = $applied;
        }

        return $out;
    }
}
