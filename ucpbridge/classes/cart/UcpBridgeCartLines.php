<?php
/**
 * Lignes d'articles d'une session UCP et panier PrestaShop sous-jacent : résolution des line_items demandés, création
 * et remplissage du panier (remplacement intégral), rendu des lignes au prix réel de la boutique, cohérence panier et
 * session avant un débit.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCartLines
{
    /** @var Context */
    private $context;
    /** @var UcpBridgeCatalog */
    private $catalog;

    public function __construct(Context $context, UcpBridgeCatalog $catalog)
    {
        $this->context = $context;
        $this->catalog = $catalog;
    }

    /**
     * Valide et résout les line_items demandés.
     *
     * @return array [lignes résolues, messages d'erreur]
     */
    public function resolveLines($lineItems)
    {
        $lines = [];
        $errors = [];
        if (!is_array($lineItems) || !$lineItems) {
            return [[], [UcpBridgeMessage::error('invalid', UcpBridgeI18n::api('line_items is required and cannot be empty'), 'recoverable', '$.line_items')]];
        }
        foreach (array_values($lineItems) as $i => $li) {
            $qty = (int) ($li['quantity'] ?? 1);
            $path = '$.line_items[' . $i . ']';
            if ($qty < 1 || $qty > 999) {
                $errors[] = UcpBridgeMessage::error('invalid', UcpBridgeI18n::api('Invalid quantity'), 'recoverable', $path . '.quantity');
                continue;
            }
            $item = is_array($li['item'] ?? null) ? $li['item'] : [];
            $r = $this->catalog->resolve($item);
            if (!$r['ok']) {
                $extra = isset($r['variants']) ? ['variants' => $r['variants']] : [];
                $errors[] = UcpBridgeMessage::error($r['code'], $r['content'], 'recoverable', $path . '.item', $extra);
                continue;
            }
            if (!$this->catalog->canOrder($r['id_product'], $r['id_product_attribute'], $qty)) {
                $errors[] = UcpBridgeMessage::error('out_of_stock', UcpBridgeI18n::api('Insufficient stock for this variant (%count% available)', ['%count%' => $this->catalog->stock($r['id_product'], $r['id_product_attribute'])]), 'recoverable', $path);
                continue;
            }
            $lines[] = [
                'id' => (string) ($li['id'] ?? ('li_' . ($i + 1))),
                'requested_id' => (string) $item['id'],
                'requested_variant_id' => isset($item['variant_id']) ? (string) $item['variant_id'] : null,
                'id_product' => (int) $r['id_product'],
                'id_product_attribute' => (int) $r['id_product_attribute'],
                'quantity' => $qty,
            ];
        }

        return [$lines, $errors];
    }

    public function newPsCart()
    {
        $cart = new Cart();
        $cart->id_shop = (int) $this->context->shop->id;
        $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
        $cart->id_lang = (int) $this->context->language->id;
        $cart->id_currency = (int) $this->context->currency->id;
        $cart->id_customer = 0;
        $cart->id_guest = 0;
        $cart->id_carrier = 0;
        $cart->add();

        return $cart;
    }

    /** Remplace intégralement le contenu du panier PrestaShop (sémantique UCP : full replacement). */
    public function fillCart(Cart $cart, array $lines)
    {
        $this->context->cart = $cart;
        foreach ($cart->getProducts(true) as $p) {
            $cart->deleteProduct((int) $p['id_product'], (int) $p['id_product_attribute'], (int) $p['id_customization']);
        }
        foreach ($lines as $l) {
            $res = $cart->updateQty($l['quantity'], $l['id_product'], $l['id_product_attribute'] ?: null, false, 'up',
                (int) $cart->id_address_delivery, null, true, true);
            if (!$res || $res < 0) {
                return false;
            }
        }
        Cart::resetStaticCache();

        return true;
    }

    public function bindContext(Cart $cart)
    {
        $this->context->cart = $cart;
        if ($cart->id_customer) {
            $this->context->customer = new Customer((int) $cart->id_customer);
        }
    }

    /** Lignes UCP à partir du panier PrestaShop réel (prix TTC de la déclinaison, prix spécifiques inclus). */
    public function renderLines(Cart $cart, array $lines)
    {
        $byKey = [];
        foreach ($cart->getProducts(true) as $p) {
            $byKey[(int) $p['id_product'] . '-' . (int) $p['id_product_attribute']] = $p;
        }
        $out = [];
        foreach ($lines as $l) {
            $key = $l['id_product'] . '-' . $l['id_product_attribute'];
            if (!isset($byKey[$key])) {
                continue;
            }
            $p = $byKey[$key];
            $unit = (int) round(((float) $p['price_wt']) * 100);
            $total = (int) round(((float) $p['total_wt']) * 100);
            $item = [
                'id' => $l['requested_id'],
                'title' => $p['name'] . (!empty($p['attributes']) ? ' (' . $p['attributes'] . ')' : ''),
                'price' => $unit,
                'variant_id' => UcpBridgeCatalog::variantKey($l['id_product'], $l['id_product_attribute']),
                'sku' => (string) $p['reference'],
            ];
            if (!empty($p['id_image'])) {
                $parts = explode('-', (string) $p['id_image']);
                $item['image_url'] = $this->context->link->getImageLink($p['link_rewrite'], (int) end($parts), ImageType::getFormattedName('home'));
            }
            $out[] = [
                'id' => $l['id'],
                'item' => $item,
                'quantity' => (int) $p['cart_quantity'],
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $total, 'display_text' => UcpBridgeI18n::api('Subtotal incl. tax')],
                    ['type' => 'total', 'amount' => $total, 'display_text' => UcpBridgeI18n::api('Total incl. tax')],
                ],
            ];
        }

        return $out;
    }

    public static function cents($v)
    {
        return (int) round(((float) $v) * 100);
    }

    /** Lignes du panier PrestaShop cohérentes avec celles de la session (chaque ligne présente, quantité suffisante). */
    public function cartMatchesSession(array $s, Cart $cart)
    {
        $qty = [];
        foreach ($cart->getProducts(true) as $p) {
            $k = (int) $p['id_product'] . '-' . (int) $p['id_product_attribute'];
            $qty[$k] = ($qty[$k] ?? 0) + (int) $p['cart_quantity'];
        }
        if (!$qty || empty($s['payload']['lines'])) {
            return false;
        }
        foreach ($s['payload']['lines'] as $l) {
            if (($qty[$l['id_product'] . '-' . $l['id_product_attribute']] ?? 0) < (int) $l['quantity']) {
                return false;
            }
        }

        return true;
    }
}
