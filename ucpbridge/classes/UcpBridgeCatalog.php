<?php
/**
 * Résolution des articles UCP vers produit + déclinaison PrestaShop, stock et prix par déclinaison,
 * recherche catalogue.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCatalog
{
    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    private function idLang()
    {
        return (int) $this->context->language->id;
    }

    private function idShop()
    {
        return (int) $this->context->shop->id;
    }

    /** Identifiant public d'une déclinaison, au format des flux Google : {id_product}-{id_product_attribute}. */
    public static function variantKey($idProduct, $idPa)
    {
        return UcpBridgeItemRef::variantKey($idProduct, $idPa);
    }

    /**
     * Toutes les déclinaisons d'un produit, avec options lisibles, stock et prix TTC (centimes).
     *
     * @return array<int, array> indexé par id_product_attribute
     */
    public function variants($idProduct)
    {
        $idProduct = (int) $idProduct;
        $rows = Db::getInstance()->executeS('SELECT pa.id_product_attribute, pa.reference, agl.public_name AS grp, al.name AS val
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . $this->idShop() . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.id_product_attribute = pa.id_product_attribute
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute = pac.id_attribute
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON ag.id_attribute_group = a.id_attribute_group
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ' . $this->idLang() . '
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $this->idLang() . '
            WHERE pa.id_product = ' . $idProduct . '
            ORDER BY pa.id_product_attribute, ag.position');
        $out = [];
        foreach ($rows ?: [] as $r) {
            $id = (int) $r['id_product_attribute'];
            if (!isset($out[$id])) {
                $out[$id] = [
                    'id_product_attribute' => $id,
                    'id' => self::variantKey($idProduct, $id),
                    'sku' => (string) $r['reference'],
                    'options' => [],
                ];
            }
            $out[$id]['options'][] = ['name' => $r['grp'], 'label' => $r['val']];
        }
        foreach ($out as $id => &$v) {
            $v['title'] = implode(', ', array_map(function ($o) {
                return $o['name'] . ' : ' . $o['label'];
            }, $v['options']));
            $v['quantity'] = $this->stock($idProduct, $id);
            $v['available'] = $this->canOrder($idProduct, $id, 1);
            $v['price'] = $this->unitPrice($idProduct, $id);
        }

        return $out;
    }

    public function stock($idProduct, $idPa)
    {
        return (int) StockAvailable::getQuantityAvailableByProduct((int) $idProduct, (int) $idPa, $this->idShop());
    }

    /** Contrôle de stock PAR déclinaison (et non au niveau produit). */
    public function canOrder($idProduct, $idPa, $qty)
    {
        if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
            return true;
        }
        if ($this->stock($idProduct, $idPa) >= (int) $qty) {
            return true;
        }

        return (bool) Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock((int) $idProduct, $this->idShop()));
    }

    /** Prix unitaire TTC en centimes : impact de déclinaison et prix spécifiques inclus. */
    public function unitPrice($idProduct, $idPa, $qty = 1)
    {
        $price = Product::getPriceStatic((int) $idProduct, true, $idPa ? (int) $idPa : null, 6, null, false, true, max(1, (int) $qty));

        return (int) round($price * 100);
    }

    private function productIdByReference($ref)
    {
        return (int) Db::getInstance()->getValue('SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop() . '
            WHERE p.reference = \'' . pSQL($ref) . '\' ORDER BY p.id_product');
    }

    /** @return array liste des [id_product, id_product_attribute] dont la référence de déclinaison vaut $ref */
    private function combinationsByReference($ref, $idProduct = 0)
    {
        return Db::getInstance()->executeS('SELECT pa.id_product, pa.id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . $this->idShop() . '
            WHERE pa.reference = \'' . pSQL($ref) . '\'' . ($idProduct ? ' AND pa.id_product = ' . (int) $idProduct : '')) ?: [];
    }

    private function productExists($idProduct)
    {
        return (bool) Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . (int) $idProduct . ' AND id_shop = ' . $this->idShop());
    }

    private function combinationBelongs($idProduct, $idPa)
    {
        return (bool) Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product = ' . (int) $idProduct . ' AND id_product_attribute = ' . (int) $idPa);
    }

    private function fail($code, $content, array $extra = [])
    {
        return ['ok' => false, 'code' => $code, 'content' => $content] + $extra;
    }

    /**
     * Résout un article UCP. Trois formes acceptées :
     *  1. item.id = référence de déclinaison (ex. demo_1-M-BLANC)
     *  2. item.id = référence produit (ou id produit) + item.variant_id (id de déclinaison, {p}-{pa} ou référence)
     *  3. item.id = « {id_product}-{id_product_attribute} » (format des flux Google)
     * Un produit à déclinaisons sans déclinaison précisée est refusé (variant_required) : on ne choisit pas à la place du client.
     */
    public function resolve(array $item)
    {
        $id = trim((string) ($item['id'] ?? ''));
        $ref = UcpBridgeItemRef::parse($id, $item['variant_id'] ?? '');
        if ('invalid' === $ref['kind']) {
            return $this->fail('invalid', 'item.id manquant');
        }
        $idProduct = 0;
        $idPa = 0;

        if ('pair' === $ref['kind']) {
            // Forme 3 : {id_product}-{id_product_attribute}
            $idProduct = $ref['id_product'];
            $idPa = $ref['id_product_attribute'];
            if (!$this->productExists($idProduct) || ($idPa && !$this->combinationBelongs($idProduct, $idPa))) {
                return $this->fail('item_unavailable', UcpBridgeI18n::api('Item not found: %id%', ['%id%' => $id]));
            }
        } else {
            $idProduct = $this->productIdByReference($id);
            if (!$idProduct && ctype_digit($id) && $this->productExists((int) $id)) {
                $idProduct = (int) $id;
            }
            if (!$idProduct) {
                // Forme 1 : référence de déclinaison, qui doit être unique
                $combos = $this->combinationsByReference($id);
                if (1 === count($combos)) {
                    $idProduct = (int) $combos[0]['id_product'];
                    $idPa = (int) $combos[0]['id_product_attribute'];
                } elseif (count($combos) > 1) {
                    return $this->fail('ambiguous_reference', UcpBridgeI18n::api('The reference %id% designates several variants, specify variant_id', ['%id%' => $id]));
                } else {
                    return $this->fail('item_unavailable', UcpBridgeI18n::api('Item not found: %id%', ['%id%' => $id]));
                }
            } elseif (null !== $ref['variant']) {
                // Forme 2 : produit + variant_id
                $v = UcpBridgeItemRef::parseVariant($ref['variant'], $idProduct);
                if (isset($v['id_product_attribute'])) {
                    $idPa = $v['id_product_attribute'];
                } else {
                    $combos = $this->combinationsByReference($v['reference'], $idProduct);
                    $idPa = 1 === count($combos) ? (int) $combos[0]['id_product_attribute'] : 0;
                }
                if (!$idPa || !$this->combinationBelongs($idProduct, $idPa)) {
                    return $this->fail('variant_unknown', UcpBridgeI18n::api('Variant %variant% unknown for %id%', ['%variant%' => $ref['variant'], '%id%' => $id]),
                        ['variants' => $this->publicVariants($idProduct)]);
                }
            }
        }

        $product = new Product($idProduct, false, $this->idLang(), $this->idShop());
        if (!Validate::isLoadedObject($product) || !$product->active || !$product->available_for_order) {
            return $this->fail('item_unavailable', UcpBridgeI18n::api('Item not available for sale: %id%', ['%id%' => $id]));
        }
        $hasVariants = (bool) $product->hasAttributes();
        if ($hasVariants && !$idPa) {
            $list = $this->publicVariants($idProduct);
            $labels = array_map(function ($v) {
                return $v['id'] . ' (' . ($v['available'] ? $v['title'] : UcpBridgeI18n::api('%title%, sold out', ['%title%' => $v['title']])) . ')';
            }, $list);

            return $this->fail('variant_required',
                UcpBridgeI18n::api('"%name%" exists in several variants: specify item.variant_id among %variants%', ['%name%' => $product->name, '%variants%' => implode(' ; ', $labels)]),
                ['variants' => $list]);
        }
        if (!$hasVariants) {
            $idPa = 0;
        }

        return ['ok' => true, 'id_product' => $idProduct, 'id_product_attribute' => $idPa, 'name' => $product->name];
    }

    /** Produit visé par un identifiant (référence produit, id, référence de déclinaison, {p}-{pa}), actif et visible. */
    public function productIdFor($id)
    {
        $ref = UcpBridgeItemRef::parse($id, '');
        $idProduct = 0;
        if ('pair' === $ref['kind']) {
            $idProduct = $this->productExists($ref['id_product']) ? $ref['id_product'] : 0;
        } elseif ('reference' === $ref['kind']) {
            $idProduct = $this->productIdByReference($ref['reference']);
            if (!$idProduct && ctype_digit($ref['reference']) && $this->productExists((int) $ref['reference'])) {
                $idProduct = (int) $ref['reference'];
            }
            if (!$idProduct) {
                $combos = $this->combinationsByReference($ref['reference']);
                $idProduct = 1 === count($combos) ? (int) $combos[0]['id_product'] : 0;
            }
        }
        if (!$idProduct) {
            return 0;
        }
        $p = new Product($idProduct, false, $this->idLang(), $this->idShop());

        return Validate::isLoadedObject($p) && $p->active ? $idProduct : 0;
    }

    /** Liste compacte des variantes à renvoyer au client dans un message d'erreur. */
    public function publicVariants($idProduct)
    {
        return array_values(array_map(function ($v) {
            return ['id' => $v['id'], 'sku' => $v['sku'], 'title' => $v['title'], 'available' => $v['available'],
                'price' => $v['price'], 'options' => $v['options']];
        }, $this->variants($idProduct)));
    }

    /** dev.ucp.shopping.catalog.search : recherche plein texte simple (tous les mots dans le nom ou la référence). */
    public function search($query, $limit = 10)
    {
        $words = array_filter(preg_split('/\s+/u', Tools::strtolower(trim((string) $query))));
        $where = [];
        foreach (array_slice($words, 0, 8) as $w) {
            $w = pSQL(str_replace(['%', '_'], ['\%', '\_'], $w));
            $where[] = '(LOWER(pl.name) LIKE \'%' . $w . '%\' OR LOWER(p.reference) LIKE \'%' . $w . '%\')';
        }
        $sql = 'SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->idShop() . ' AND ps.active = 1 AND ps.visibility IN (\'both\', \'search\')
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $this->idLang() . ' AND pl.id_shop = ' . $this->idShop()
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY p.id_product LIMIT ' . max(1, min(50, (int) $limit));
        $products = [];
        foreach (Db::getInstance()->executeS($sql) ?: [] as $r) {
            $products[] = $this->productView((int) $r['id_product']);
        }

        return $products;
    }

    public function productView($idProduct)
    {
        $p = new Product($idProduct, false, $this->idLang(), $this->idShop());
        $link = $this->context->link;
        $currency = $this->context->currency->iso_code;
        $variants = $this->variants($idProduct);
        $prices = $variants ? array_column($variants, 'price') : [$this->unitPrice($idProduct, 0)];
        $options = [];
        foreach ($variants as $v) {
            foreach ($v['options'] as $o) {
                $options[$o['name']][$o['label']] = ['label' => $o['label']];
            }
        }
        $cover = Product::getCover($idProduct);
        $view = [
            'id' => $p->reference ?: (string) $idProduct,
            'title' => $p->name,
            'description' => ['plain' => trim(strip_tags((string) $p->description_short))],
            'url' => $link->getProductLink($p),
            'price_range' => [
                'min' => ['amount' => min($prices), 'currency' => $currency],
                'max' => ['amount' => max($prices), 'currency' => $currency],
            ],
            'media' => $cover ? [['type' => 'image', 'url' => $link->getImageLink($p->link_rewrite, (int) $cover['id_image'], ImageType::getFormattedName('large')), 'alt_text' => $p->name]] : [],
            'options' => array_values(array_map(function ($name, $vals) {
                return ['name' => $name, 'values' => array_values($vals)];
            }, array_keys($options), $options)),
            'variants' => [],
        ];
        foreach ($variants as $v) {
            $view['variants'][] = [
                'id' => $v['id'],
                'sku' => $v['sku'],
                'title' => $v['title'],
                'price' => ['amount' => $v['price'], 'currency' => $currency],
                'availability' => ['available' => $v['available']],
                'options' => $v['options'],
            ];
        }
        if (!$variants) {
            $view['variants'][] = [
                'id' => self::variantKey($idProduct, 0),
                'sku' => $p->reference,
                'title' => $p->name,
                'price' => ['amount' => $prices[0], 'currency' => $currency],
                'availability' => ['available' => $this->canOrder($idProduct, 0, 1)],
                'options' => [],
            ];
        }

        return $view;
    }
}
