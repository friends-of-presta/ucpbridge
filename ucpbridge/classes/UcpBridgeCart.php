<?php
/**
 * Coeur métier : panier UCP transférable (dev.ucp.shopping.cart), caisse native
 * (dev.ucp.shopping.checkout + fulfillment), commande (dev.ucp.shopping.order).
 * Chaque session UCP s'appuie sur un vrai panier PrestaShop (ps_cart) : prix, TVA,
 * transporteurs et stock sont ceux de la boutique, sans recalcul parallèle.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCart
{
    const TTL = 86400;
    /**
     * Statuts internes qui ne bougent plus (ou seulement par le détenteur de la réservation) :
     * handed_off (lien de reprise ouvert, la session a quitté l'API) et failed (débit puis commande impossible,
     * remboursement tenté) sont exposés à l'agent comme « canceled », avec un message explicite.
     */
    const LOCKED = ['complete_in_progress', 'completed', 'canceled', 'handed_off', 'failed', 'updating', 'reconciling'];
    const TERMINAL = ['completed', 'canceled', 'handed_off', 'failed'];
    /**
     * Réservations en cours : complete_in_progress (finalisation), updating (mise à jour du panier PrestaShop, posée
     * AVANT toute modification), reconciling (rapprochement d'une session bloquée). Une seule à la fois par session.
     */
    const BUSY = ['complete_in_progress', 'updating', 'reconciling'];
    /** Statuts d'une session de caisse modifiable par update_checkout. */
    const EDITABLE = ['incomplete', 'ready_for_complete', 'requires_escalation'];

    /** @var Context */
    private $context;
    /** @var Ucpbridge */
    private $module;
    /** @var UcpBridgeCatalog */
    private $catalog;

    public function __construct(Context $context, Ucpbridge $module)
    {
        $this->context = $context;
        $this->module = $module;
        $this->catalog = new UcpBridgeCatalog($context);
    }

    public function catalog()
    {
        return $this->catalog;
    }

    /* ======================= Gestionnaires de paiement ======================= */

    /** Gestionnaires annoncés : ceux des adaptateurs inscrits au registre (module + modules tiers). */
    public static function paymentHandlers()
    {
        return UcpBridgePaymentRegistry::get()->handlers();
    }

    /* ====================== Client rattaché (identity linking) ====================== */

    /** @var int client PrestaShop authentifié par jeton OAuth (0 = invité) */
    private $idUser = 0;

    public function setUser($idCustomer)
    {
        $this->idUser = (int) $idCustomer;
    }

    /** @var string en-tête UCP-Agent de l'appelant (consigné dans le journal de preuve) */
    private $agent = '';

    public function setAgent($agent)
    {
        $this->agent = substr(trim((string) $agent), 0, 512);
    }

    /** @var string appelant propriétaire : « platform » (clé API) ou « user:{client_id}:{id_customer} » (jeton OAuth) */
    private $owner = '';

    public function setCaller($owner)
    {
        $this->owner = (string) $owner;
    }

    /**
     * Cloisonnement strict : un panier, une session ou une commande n'est lisible et modifiable que par l'appelant
     * qui l'a créé (même plateforme, ou même client OAuth et même client final). Aucune ressource « ouverte à tous ».
     */
    public function ownedByCaller(array $s)
    {
        return '' !== $this->owner && hash_equals((string) ($s['owner'] ?? ''), $this->owner);
    }

    public static function isTerminal($status)
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function isBusy($status)
    {
        return in_array($status, self::BUSY, true);
    }

    /** Statut UCP présenté à l'agent (les statuts internes deviennent des statuts de la spécification). */
    public static function exposedStatus($status)
    {
        $map = ['handed_off' => 'canceled', 'failed' => 'canceled', 'updating' => 'incomplete', 'reconciling' => 'complete_in_progress'];

        return $map[$status] ?? $status;
    }

    /**
     * Réservation atomique : passe la session de l'un des statuts $from au statut $to (une seule ligne modifiée exigée).
     * Optionnellement limitée aux sessions dont la dernière écriture est antérieure à $before (rapprochement).
     */
    private function reserve($id, array $from, $to, $before = null)
    {
        $db = Db::getInstance();
        $db->update('ucpbridge_session', ['status' => pSQL($to), 'date_upd' => date('Y-m-d H:i:s')],
            'id_session = \'' . pSQL($id) . '\' AND status IN (\'' . implode('\',\'', array_map('pSQL', $from)) . '\')'
            . (null !== $before ? ' AND date_upd < \'' . pSQL($before) . '\'' : ''));

        return 1 === (int) $db->Affected_Rows();
    }

    /** Rattache le panier au vrai client : identité, adresse enregistrée proposée par défaut. */
    private function attachUser(array &$s, Cart $cart)
    {
        $c = new Customer($this->idUser);
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

    /* ============================ Stockage sessions ============================ */

    public function load($id, $kind)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ucpbridge_session`
            WHERE id_session = \'' . pSQL($id) . '\' AND kind = \'' . pSQL($kind) . '\'');
        if (!$row) {
            return null;
        }
        $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        if (strtotime($row['expires_at']) < time() && !in_array($row['status'], self::LOCKED, true)) {
            $row['status'] = 'canceled';
            $this->save($row);
        }

        return $row;
    }

    /** Commande exposée par son identifiant opaque (ord_ + 24 caractères hexadécimaux), jamais par id_order. */
    public function loadByOrderRef($ref)
    {
        if (!preg_match('/^ord_[a-f0-9]{24}$/', (string) $ref)) {
            return null;
        }
        $id = Db::getInstance()->getValue('SELECT id_session FROM `' . _DB_PREFIX_ . 'ucpbridge_session`
            WHERE kind = \'checkout\' AND id_order > 0 AND order_ref = \'' . pSQL($ref) . '\'');

        return $id ? $this->load($id, 'checkout') : null;
    }

    /**
     * Enregistre la session. Sans $from : ne réécrit jamais une session réservée ou terminée (statuts LOCKED).
     * Avec $from : mise à jour conditionnelle (le statut en base doit être l'un de $from), atomique.
     *
     * @return bool true si la ligne a été créée ou modifiée
     */
    private function save(array $s, array $from = null)
    {
        $db = Db::getInstance();
        $now = date('Y-m-d H:i:s');
        $data = [
            'id_cart' => (int) $s['id_cart'],
            'id_order' => (int) ($s['id_order'] ?? 0),
            'status' => pSQL($s['status']),
            'payload' => pSQL(json_encode($s['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true),
            'expires_at' => pSQL($s['expires_at']),
            'date_upd' => $now,
        ];
        if (!empty($s['order_ref'])) {
            $data['order_ref'] = pSQL($s['order_ref']);
        }
        $id = pSQL($s['id_session']);
        $exists = $db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'ucpbridge_session` WHERE id_session = \'' . $id . '\'');
        if ($exists) {
            $guard = null === $from
                ? ' AND status NOT IN (\'' . implode('\',\'', self::LOCKED) . '\')'
                : ' AND status IN (\'' . implode('\',\'', array_map('pSQL', $from)) . '\')';
            $db->update('ucpbridge_session', $data, 'id_session = \'' . $id . '\'' . $guard);

            return 1 === (int) $db->Affected_Rows();
        }
        $data['id_session'] = $id;
        $data['kind'] = pSQL($s['kind']);
        $data['owner'] = pSQL($this->owner);
        $data['date_add'] = $now;

        return (bool) $db->insert('ucpbridge_session', $data);
    }

    /* ============================== Lignes d'articles ============================== */

    private static function msg($code, $content, $severity = 'recoverable', $path = null, array $extra = [])
    {
        $m = ['type' => 'error', 'code' => $code, 'content' => $content, 'severity' => $severity];
        if ($path) {
            $m['path'] = $path;
        }

        return $m + $extra;
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
            return [[], [self::msg('invalid', 'line_items est requis et ne peut pas être vide', 'recoverable', '$.line_items')]];
        }
        foreach (array_values($lineItems) as $i => $li) {
            $qty = (int) ($li['quantity'] ?? 1);
            $path = '$.line_items[' . $i . ']';
            if ($qty < 1 || $qty > 999) {
                $errors[] = self::msg('invalid', 'Quantité invalide', 'recoverable', $path . '.quantity');
                continue;
            }
            $item = is_array($li['item'] ?? null) ? $li['item'] : [];
            $r = $this->catalog->resolve($item);
            if (!$r['ok']) {
                $extra = isset($r['variants']) ? ['variants' => $r['variants']] : [];
                $errors[] = self::msg($r['code'], $r['content'], 'recoverable', $path . '.item', $extra);
                continue;
            }
            if (!$this->catalog->canOrder($r['id_product'], $r['id_product_attribute'], $qty)) {
                $errors[] = self::msg('out_of_stock', 'Stock insuffisant pour cette déclinaison ('
                    . $this->catalog->stock($r['id_product'], $r['id_product_attribute']) . ' disponible(s))', 'recoverable', $path);
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

    private function newPsCart()
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
    private function fillCart(Cart $cart, array $lines)
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

    private function bindContext(Cart $cart)
    {
        $this->context->cart = $cart;
        if ($cart->id_customer) {
            $this->context->customer = new Customer((int) $cart->id_customer);
        }
    }

    /** Lignes UCP à partir du panier PrestaShop réel (prix TTC de la déclinaison, prix spécifiques inclus). */
    private function renderLines(Cart $cart, array $lines)
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
                    ['type' => 'subtotal', 'amount' => $total, 'display_text' => 'Sous-total TTC'],
                    ['type' => 'total', 'amount' => $total, 'display_text' => 'Total TTC'],
                ],
            ];
        }

        return $out;
    }

    private static function cents($v)
    {
        return (int) round(((float) $v) * 100);
    }

    /* ================================== Paniers ================================== */

    private function cartEnvelope()
    {
        return [
            'version' => Ucpbridge::UCP_VERSION,
            'status' => 'success',
            'capabilities' => [
                'dev.ucp.shopping.cart' => [['version' => Ucpbridge::UCP_VERSION]],
                'dev.ucp.shopping.checkout' => [['version' => Ucpbridge::UCP_VERSION]],
                'dev.ucp.shopping.discount' => [['version' => Ucpbridge::UCP_VERSION]],
            ],
        ];
    }

    public function errorDoc(array $messages, array $capabilities)
    {
        $caps = [];
        foreach ($capabilities as $c) {
            $caps[$c] = [['version' => Ucpbridge::UCP_VERSION]];
        }

        return [
            'ucp' => ['version' => Ucpbridge::UCP_VERSION, 'status' => 'error', 'capabilities' => $caps],
            'messages' => $messages,
            'continue_url' => $this->context->link->getPageLink('index', true),
        ];
    }

    public function createCart(array $body)
    {
        list($lines, $errors) = $this->resolveLines($body['line_items'] ?? null);
        if ($errors) {
            return [200, $this->errorDoc($errors, ['dev.ucp.shopping.cart'])];
        }
        $cart = $this->newPsCart();
        if (!$this->fillCart($cart, $lines)) {
            return [200, $this->errorDoc([self::msg('out_of_stock', 'Impossible d\'ajouter les articles au panier', 'unrecoverable')], ['dev.ucp.shopping.cart'])];
        }
        if ($this->idUser) {
            $cart->id_customer = $this->idUser;
            $cart->secure_key = (new Customer($this->idUser))->secure_key;
            $cart->update();
        }
        $s = [
            'id_session' => 'cart_' . bin2hex(random_bytes(10)),
            'kind' => 'cart',
            'owner' => $this->owner,
            'id_cart' => (int) $cart->id,
            'status' => 'active',
            'payload' => ['lines' => $lines, 'context' => $body['context'] ?? null, 'buyer' => $body['buyer'] ?? null,
                'user_customer' => $this->idUser],
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL),
        ];
        $this->bindContext($cart);
        $msgs = $this->discounts()->sync($s, $cart, $body['discounts']['codes'] ?? [], false);
        $this->save($s);

        return [201, $this->cartDoc($s, $msgs)];
    }

    /** Détail d'un produit (get_product) : produit et toutes ses variantes. */
    public function productDoc($id)
    {
        $idProduct = $this->catalog->productIdFor($id);
        if (!$idProduct) {
            return $this->errorDoc([self::msg('not_found', 'Produit introuvable : ' . $id, 'unrecoverable', '$.id')], ['dev.ucp.shopping.catalog.search']);
        }

        return [
            'ucp' => ['version' => Ucpbridge::UCP_VERSION, 'status' => 'success',
                'capabilities' => ['dev.ucp.shopping.catalog.search' => [['version' => Ucpbridge::UCP_VERSION]]]],
            'product' => $this->catalog->productView($idProduct),
        ];
    }

    public function discounts()
    {
        return new UcpBridgeDiscount($this->context);
    }

    public function cartDoc(array $s, $msgs = null)
    {
        if ('handed_off' === $s['status']) {
            return $this->errorDoc([self::handedOffMsg()], ['dev.ucp.shopping.cart']);
        }
        if ('active' !== $s['status'] && 'updating' !== $s['status']) {
            return $this->errorDoc([self::msg('not_found', 'Panier expiré ou annulé', 'unrecoverable')], ['dev.ucp.shopping.cart']);
        }
        $cart = new Cart((int) $s['id_cart']);
        $this->bindContext($cart);
        $lines = $this->renderLines($cart, $s['payload']['lines']);
        $totals = UcpBridgeTotals::cart(self::cents($cart->getOrderTotal(true, Cart::ONLY_PRODUCTS)),
            self::cents($cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING)));
        $exp = strtotime($s['expires_at']);
        $doc = [
            'ucp' => $this->cartEnvelope(),
            'id' => $s['id_session'],
            'line_items' => $lines,
            'currency' => $this->context->currency->iso_code,
            'totals' => $totals,
            'discounts' => $this->discounts()->render($s, $cart, true, false),
            // Lien signé (HMAC, 24 h) : rattache CE panier PrestaShop à la session du visiteur.
            'continue_url' => $this->module->buildContinueUrl((int) $s['id_cart'], max(60, $exp - time())),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp),
            'links' => $this->links(),
        ];
        if (!empty($s['payload']['buyer'])) {
            $doc['buyer'] = $s['payload']['buyer'];
        }
        $msgs = null === $msgs ? ($s['payload']['discount_messages'] ?? []) : $msgs;
        $msgs = array_merge(array_values($msgs), $this->disclosures($s['payload']['context']['address_country'] ?? null));
        if ($msgs) {
            $doc['messages'] = array_values($msgs);
        }

        return $doc;
    }

    /** Mentions obligatoires (presentation « disclosure ») pour le pays de livraison. */
    private function disclosures($iso)
    {
        $d = $this->module->withdrawalDisclosure(is_string($iso) ? $iso : null);

        return $d ? [$d] : [];
    }

    private static function handedOffMsg()
    {
        return self::msg('checkout_handed_off', 'L\'acheteur a repris ce panier sur la boutique (lien de reprise ouvert) : '
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
        list($lines, $errors) = $this->resolveLines($body['line_items'] ?? null);
        if ($errors) {
            return [200, $this->errorDoc($errors, ['dev.ucp.shopping.cart'])];
        }
        // Réservation AVANT de toucher au panier PrestaShop : le lien de reprise (handoff) et une autre mise à jour
        // ne peuvent plus s'intercaler. Refus 409 si la session est déjà réservée ou sortie de l'API.
        if (!$this->reserve($s['id_session'], ['active'], 'updating')) {
            $cur = $this->load($s['id_session'], 'cart') ?: $s;
            if ('handed_off' === $cur['status']) {
                return [409, $this->cartDoc($cur)];
            }

            return [409, $this->errorDoc([self::msg('not_modifiable', 'Panier en cours de modification : réessayer', 'recoverable')], ['dev.ucp.shopping.cart'])];
        }
        $s = $this->load($s['id_session'], 'cart');
        $s['status'] = 'active';
        try {
            $cart = new Cart((int) $s['id_cart']);
            if ($cart->orderExists()) {
                $this->save($s, ['updating']);

                return [200, $this->errorDoc([self::msg('not_found', 'Ce panier a déjà donné lieu à une commande', 'unrecoverable')], ['dev.ucp.shopping.cart'])];
            }
            $this->fillCart($cart, $lines);
            $s['payload']['lines'] = $lines;
            if (isset($body['buyer'])) {
                $s['payload']['buyer'] = $body['buyer'];
            }
            $this->bindContext($cart);
            $msgs = $this->discounts()->sync($s, $cart, $body['discounts']['codes'] ?? null, false);
        } catch (Throwable $e) {
            $this->save($s, ['updating']);
            throw $e;
        }
        $this->save($s, ['updating']);

        return [200, $this->cartDoc($s, $msgs)];
    }

    public function cancelCart(array $s)
    {
        $s['status'] = 'canceled';
        $this->save($s);

        return [200, $this->errorDoc([self::msg('canceled', 'Panier annulé', 'unrecoverable')], ['dev.ucp.shopping.cart'])];
    }

    /** Liens légaux construits depuis les pages CMS configurées (titres en langue de la boutique). */
    private function links()
    {
        return $this->module->legalLinks();
    }

    /* =============================== Caisse native =============================== */

    private function checkoutEnvelope()
    {
        return [
            'version' => Ucpbridge::UCP_VERSION,
            'status' => 'success',
            'capabilities' => [
                'dev.ucp.shopping.checkout' => [['version' => Ucpbridge::UCP_VERSION]],
                'dev.ucp.shopping.fulfillment' => [['version' => Ucpbridge::UCP_VERSION]],
                'dev.ucp.shopping.discount' => [['version' => Ucpbridge::UCP_VERSION]],
            ],
            'payment_handlers' => (object) self::paymentHandlers(),
        ];
    }

    public function createCheckout(array $body)
    {
        $lineItems = $body['line_items'] ?? null;
        if (!$lineItems && !empty($body['cart_id'])) {
            // Conversion d'un panier UCP existant en session de caisse.
            $c = $this->load((string) $body['cart_id'], 'cart');
            if (!$c || 'active' !== $c['status'] || !$this->ownedByCaller($c)) {
                return [404, $this->errorDoc([self::msg('not_found', 'Panier introuvable', 'unrecoverable', '$.cart_id')], ['dev.ucp.shopping.checkout'])];
            }
            $lineItems = array_map(function ($l) {
                return ['id' => $l['id'], 'item' => array_filter(['id' => $l['requested_id'], 'variant_id' => $l['requested_variant_id']]), 'quantity' => $l['quantity']];
            }, $c['payload']['lines']);
            if (!isset($body['discounts']) && !empty($c['payload']['discount_codes'])) {
                $body['discounts'] = ['codes' => $c['payload']['discount_codes']];
            }
        }
        list($lines, $errors) = $this->resolveLines($lineItems);
        if ($errors) {
            return [200, $this->errorDoc($errors, ['dev.ucp.shopping.checkout'])];
        }
        $cart = $this->newPsCart();
        if (!$this->fillCart($cart, $lines)) {
            return [200, $this->errorDoc([self::msg('out_of_stock', 'Impossible d\'ajouter les articles', 'unrecoverable')], ['dev.ucp.shopping.checkout'])];
        }
        $s = [
            'id_session' => 'chk_' . bin2hex(random_bytes(10)),
            'kind' => 'checkout',
            'owner' => $this->owner,
            'id_cart' => (int) $cart->id,
            'status' => 'incomplete',
            'payload' => ['lines' => $lines, 'discount_codes' => []],
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL),
        ];
        if ($this->idUser) {
            $this->attachUser($s, $cart);
        }
        if (!isset($body['discounts'])) {
            $body['discounts'] = ['codes' => []];
        }
        $msgs = $this->applyUpdate($s, $cart, $body, false);
        $this->save($s);

        return [201, $this->checkoutDoc($s, $msgs)];
    }

    public function updateCheckout(array $s, array $body)
    {
        if (self::isTerminal($s['status'])) {
            return [200, $this->checkoutDoc($s, [self::msg('not_modifiable', 'Session ' . self::exposedStatus($s['status']) . ' : modification impossible', 'unrecoverable')])];
        }
        // Réservation AVANT toute modification du panier PrestaShop (même statut que celui lu : une écriture concurrente
        // fait échouer la réservation). Une session réservée (finalisation, autre mise à jour, rapprochement) est refusée
        // en 409 avec son état réel : jamais de totaux d'une mise à jour non enregistrée.
        $prev = $s['status'];
        if (self::isBusy($prev) || !$this->reserve($s['id_session'], [$prev], 'updating')) {
            $cur = $this->load($s['id_session'], 'checkout') ?: $s;
            if (self::isTerminal($cur['status'])) {
                return [200, $this->checkoutDoc($cur, [self::msg('not_modifiable', 'Session ' . self::exposedStatus($cur['status']) . ' : modification impossible', 'unrecoverable')])];
            }

            return [409, $this->checkoutDoc($cur, [self::msg('not_modifiable', 'Session réservée (finalisation ou mise à jour en cours) : modification refusée', 'recoverable')])];
        }
        $s = $this->load($s['id_session'], 'checkout');
        $s['status'] = $prev;
        try {
            $cart = new Cart((int) $s['id_cart']);
            $msgs = $this->applyUpdate($s, $cart, $body, true);
        } catch (Throwable $e) {
            $s['status'] = $prev;
            $this->save($s, ['updating']);
            throw $e;
        }
        $this->save($s, ['updating']);

        return [200, $this->checkoutDoc($s, $msgs)];
    }

    /** Applique line_items, buyer et fulfillment. Retourne les messages d'erreur de saisie. */
    private function applyUpdate(array &$s, Cart $cart, array $body, $replaceLines)
    {
        $msgs = [];
        $this->bindContext($cart);
        if ($replaceLines && isset($body['line_items'])) {
            list($lines, $errors) = $this->resolveLines($body['line_items']);
            if ($errors) {
                $msgs = array_merge($msgs, $errors);
            } else {
                $this->fillCart($cart, $lines);
                $s['payload']['lines'] = $lines;
            }
        }
        if (isset($body['buyer']['consent'])) {
            // buyer_consent : seuls les booléens explicites sont retenus, jamais de valeur présumée.
            $s['payload']['consent'] = UcpBridgeConsent::normalize($body['buyer']['consent']);
        }
        if (isset($body['buyer']) && is_array($body['buyer']) && !empty($s['payload']['user_customer'])) {
            // Client authentifié : l'identité vient du compte PrestaShop, seul le téléphone est repris.
            if (!empty($body['buyer']['phone_number'])) {
                $s['payload']['buyer']['phone_number'] = (string) $body['buyer']['phone_number'];
            }
        } elseif (isset($body['buyer']) && is_array($body['buyer'])) {
            $b = $body['buyer'];
            if (!empty($b['email']) && !Validate::isEmail($b['email'])) {
                $msgs[] = self::msg('invalid', 'Adresse e-mail invalide', 'recoverable', '$.buyer.email');
                unset($b['email']);
            }
            $s['payload']['buyer'] = array_merge($s['payload']['buyer'] ?? [], array_intersect_key($b, array_flip(['email', 'first_name', 'last_name', 'phone_number'])));
            $this->syncCustomer($s, $cart);
        }
        $method = $body['fulfillment']['methods'][0] ?? null;
        if (is_array($method)) {
            $dests = $method['destinations'] ?? null;
            if (is_array($dests) && $dests) {
                $selected = $method['selected_destination_id'] ?? null;
                $dest = $dests[0];
                foreach ($dests as $d) {
                    if ($selected && ($d['id'] ?? null) === $selected) {
                        $dest = $d;
                    }
                }
                $dest['id'] = $dest['id'] ?? 'dest_1';
                // Adresse du carnet renvoyée telle quelle par l'agent : rien à changer.
                $unchanged = !empty($s['payload']['address_saved']) && $dest['id'] === ($s['payload']['destination']['id'] ?? null);
                $err = $unchanged ? null : $this->applyDestination($s, $cart, $dest);
                if ($err) {
                    $msgs[] = $err;
                }
            }
            $opt = $method['groups'][0]['selected_option_id'] ?? null;
            if ($opt) {
                $err = $this->applyCarrier($s, $cart, (string) $opt);
                if ($err) {
                    $msgs[] = $err;
                }
            }
        }
        // Codes promo en dernier : les règles peuvent dépendre du pays et du transporteur.
        $codes = isset($body['discounts']) ? ($body['discounts']['codes'] ?? []) : null;
        $msgs = array_merge($msgs, $this->discounts()->sync($s, $cart, $codes, !empty($s['payload']['id_carrier'])));
        $s['status'] = $this->computeStatus($s, $cart);

        return $msgs;
    }

    private function syncCustomer(array &$s, Cart $cart)
    {
        $b = $s['payload']['buyer'] ?? [];
        if (empty($b['email'])) {
            return;
        }
        $c = !empty($s['payload']['id_customer']) ? new Customer((int) $s['payload']['id_customer']) : new Customer();
        $c->email = $b['email'];
        $c->firstname = $this->cleanName($b['first_name'] ?? ($s['payload']['destination']['first_name'] ?? 'Client'));
        $c->lastname = $this->cleanName($b['last_name'] ?? ($s['payload']['destination']['last_name'] ?? 'UCP'));
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

    private function cleanName($n)
    {
        $n = trim(preg_replace('/[0-9!<>,;?=+()@#"°{}_$%:]/u', '', (string) $n));

        return '' !== $n ? Tools::substr($n, 0, 255) : 'Client';
    }

    private function applyDestination(array &$s, Cart $cart, array $d)
    {
        foreach (['street_address', 'address_locality', 'postal_code', 'address_country'] as $f) {
            if (empty($d[$f])) {
                return self::msg('missing', 'Champ d\'adresse manquant : ' . $f, 'recoverable', '$.fulfillment.methods[0].destinations[0].' . $f);
            }
        }
        $idCountry = (int) Country::getByIso(Tools::strtoupper((string) $d['address_country']), true);
        if (!$idCountry) {
            return self::msg('address_undeliverable', 'Pays non livré par la boutique : ' . $d['address_country'], 'recoverable', '$.fulfillment.methods[0].destinations[0].address_country');
        }
        $country = new Country($idCountry);
        if (!$country->checkZipCode((string) $d['postal_code'])) {
            return self::msg('invalid', 'Code postal invalide pour ' . $country->iso_code, 'recoverable', '$.fulfillment.methods[0].destinations[0].postal_code');
        }
        // Adresse du carnet client : jamais modifiée, une nouvelle adresse est créée à côté.
        $reuse = !empty($s['payload']['id_address']) && empty($s['payload']['address_saved']);
        $a = $reuse ? new Address((int) $s['payload']['id_address']) : new Address();
        $buyer = $s['payload']['buyer'] ?? [];
        $a->id_customer = (int) $cart->id_customer;
        $a->alias = 'UCP';
        $a->firstname = $this->cleanName($d['first_name'] ?? ($buyer['first_name'] ?? 'Client'));
        $a->lastname = $this->cleanName($d['last_name'] ?? ($buyer['last_name'] ?? 'UCP'));
        $a->address1 = Tools::substr((string) $d['street_address'], 0, 128);
        $a->address2 = Tools::substr((string) ($d['extended_address'] ?? ''), 0, 128);
        $a->postcode = (string) $d['postal_code'];
        $a->city = Tools::substr((string) $d['address_locality'], 0, 64);
        $a->id_country = $idCountry;
        $a->id_state = !empty($d['address_region']) ? (int) State::getIdByIso((string) $d['address_region'], $idCountry) : 0;
        $a->phone = isset($buyer['phone_number']) ? (string) $buyer['phone_number'] : '';
        $check = $a->validateFields(false, true);
        if (true !== $check) {
            return self::msg('invalid', 'Adresse refusée : ' . strip_tags((string) $check), 'recoverable', '$.fulfillment.methods[0].destinations[0]');
        }
        $ok = Validate::isLoadedObject($a) ? $a->update() : $a->add();
        if (!$ok) {
            return self::msg('invalid', 'Adresse refusée par la boutique', 'recoverable', '$.fulfillment.methods[0].destinations[0]');
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
    private function carrierOptions(Cart $cart)
    {
        if (!$cart->id_address_delivery) {
            return [];
        }
        $this->bindContext($cart);
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
                'totals' => [['type' => 'total', 'amount' => self::cents($opt['total_price_with_tax']), 'display_text' => 'TTC']],
            ];
        }

        return $out;
    }

    private function applyCarrier(array &$s, Cart $cart, $optionId)
    {
        if (!$cart->id_address_delivery) {
            return self::msg('missing', 'Choisir une adresse avant le transporteur', 'recoverable', '$.fulfillment.methods[0].destinations');
        }
        $opts = $this->carrierOptions($cart);
        if (!isset($opts[$optionId])) {
            return self::msg('invalid', 'Option de livraison inconnue : ' . $optionId, 'recoverable', '$.fulfillment.methods[0].groups[0].selected_option_id');
        }
        $idCarrier = (int) substr($optionId, strlen('carrier_'));
        $cart->setDeliveryOption([(int) $cart->id_address_delivery => $idCarrier . ',']);
        $cart->id_carrier = $idCarrier;
        $cart->update();
        Cart::resetStaticCache();
        $s['payload']['id_carrier'] = $idCarrier;

        return null;
    }

    private function missing(array $s, Cart $cart)
    {
        $m = [];
        if (empty($s['payload']['buyer']['email'])) {
            $m[] = self::msg('missing', 'E-mail de l\'acheteur requis', 'recoverable', '$.buyer.email');
        }
        if (empty($s['payload']['id_address'])) {
            $m[] = self::msg('missing', 'Adresse de livraison requise', 'recoverable', '$.fulfillment.methods[0].destinations');
        } elseif (empty($s['payload']['id_carrier'])) {
            $m[] = $this->carrierOptions($cart)
                ? self::msg('missing', 'Choisir une option de livraison', 'recoverable', '$.fulfillment.methods[0].groups[0].selected_option_id')
                : self::msg('address_undeliverable', 'Aucun transporteur ne livre cette adresse', 'recoverable', '$.fulfillment.methods[0].destinations');
        }
        foreach ($s['payload']['lines'] as $i => $l) {
            if (!$this->catalog->canOrder($l['id_product'], $l['id_product_attribute'], $l['quantity'])) {
                $m[] = self::msg('out_of_stock', 'Stock insuffisant pour cette déclinaison', 'recoverable', '$.line_items[' . $i . ']');
            }
        }

        return $m;
    }

    private function computeStatus(array $s, Cart $cart)
    {
        if (in_array($s['status'], self::LOCKED, true)
            || ('requires_escalation' === $s['status'] && !empty($s['payload']['escalation_url']))) {
            return $s['status'];
        }
        if ($this->missing($s, $cart)) {
            return 'incomplete';
        }

        return $this->needsTermsReview($s) ? 'requires_escalation' : 'ready_for_complete';
    }

    /** Option « relecture des CGV chez le marchand » active et CGV pas encore acceptées. */
    private function needsTermsReview(array $s)
    {
        return $this->module->termsReviewRequired() && empty($s['payload']['terms_acceptance']);
    }

    /** Acceptation des CGV sur la page du module : date, version, IP ; la session redevient finalisable. */
    public function acceptTerms(array $s, $ip, $userAgent)
    {
        $t = $this->module->termsInfo();
        $s['payload']['terms_acceptance'] = [
            'accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => $t['version'],
            'id_cms' => $t['id'],
            'ip' => (string) $ip,
            'user_agent' => substr((string) $userAgent, 0, 255),
        ];
        $cart = new Cart((int) $s['id_cart']);
        $this->bindContext($cart);
        $s['status'] = $this->computeStatus($s, $cart);
        $this->save($s);

        return $s;
    }

    /** Totaux cohérents (somme des lignes = total) : sous-total HT, réductions HT, livraison HT, TVA, total TTC. */
    private function checkoutTotals(array $s, Cart $cart)
    {
        $hasCarrier = !empty($s['payload']['id_carrier']);
        $mode = $hasCarrier ? Cart::BOTH : Cart::BOTH_WITHOUT_SHIPPING;
        $country = $cart->id_address_delivery ? Country::getIsoById((int) (new Address((int) $cart->id_address_delivery))->id_country) : null;

        return UcpBridgeTotals::checkout(
            self::cents($cart->getOrderTotal(false, Cart::ONLY_PRODUCTS)),
            $hasCarrier ? self::cents($cart->getOrderTotal(false, Cart::ONLY_SHIPPING)) : null,
            self::cents($cart->getOrderTotal(false, $mode)),
            self::cents($cart->getOrderTotal(true, $mode)),
            $country
        );
    }

    public function checkoutDoc(array $s, array $extraMsgs = [])
    {
        $cart = new Cart((int) $s['id_cart']);
        $this->bindContext($cart);
        $lines = $this->renderLines($cart, $s['payload']['lines']);
        $liIds = array_column($lines, 'id');
        $doc = [
            'ucp' => $this->checkoutEnvelope(),
            'id' => $s['id_session'],
            'status' => self::exposedStatus($s['status']),
            'currency' => $this->context->currency->iso_code,
            'line_items' => $lines,
            'totals' => $this->checkoutTotals($s, $cart),
            'discounts' => $this->discounts()->render($s, $cart, false, !empty($s['payload']['id_carrier'])),
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
            $group = ['id' => 'package_1', 'line_item_ids' => $liIds, 'options' => array_values($this->carrierOptions($cart))];
            if (!empty($s['payload']['id_carrier'])) {
                $group['selected_option_id'] = 'carrier_' . (int) $s['payload']['id_carrier'];
            }
            $method['groups'] = [$group];
        }
        $doc['fulfillment'] = ['methods' => [$method]];
        $messages = array_merge($extraMsgs, $this->terminalMessages($s));
        if (!self::isTerminal($s['status']) && !self::isBusy($s['status'])) {
            $messages = array_merge($messages, $this->missing($s, $cart));
            if (!$extraMsgs && !empty($s['payload']['discount_messages'])) {
                $messages = array_merge($messages, $s['payload']['discount_messages']);
            }
        }
        $review = 'requires_escalation' === $s['status'] && empty($s['payload']['escalation_url']);
        if ($review) {
            $messages[] = self::msg('terms_review_required', 'L\'acheteur doit relire et accepter les conditions générales de vente '
                . 'sur la boutique avant la commande', 'requires_buyer_review', '$.links[0]');
        }
        if ('canceled' !== self::exposedStatus($s['status'])) {
            $messages = array_merge($messages, $this->disclosures($s['payload']['destination']['address_country'] ?? null));
        }
        if ('complete_in_progress' === $s['status'] && !empty($s['payload']['charge_unknown'])) {
            $messages[] = self::msg('payment_verification', 'Résultat du paiement inconnu : ne pas relancer avec un autre moyen de paiement, '
                . 'le marchand vérifie auprès du prestataire de paiement et rembourse si un débit a eu lieu', 'requires_buyer_input');
        }
        if ('requires_escalation' === $s['status'] && !empty($s['payload']['escalation_url'])) {
            $messages[] = self::msg('requires_3ds', 'Authentification forte (3-D Secure) requise : l\'acheteur doit finir le paiement sur la boutique', 'requires_buyer_input');
        }
        if ($messages) {
            $doc['messages'] = array_values($messages);
        }
        if ('completed' === $s['status'] && !empty($s['id_order'])) {
            $doc['order'] = ['id' => (string) $s['order_ref'], 'permalink_url' => $this->orderPermalink((int) $s['id_order'])];
            if (!empty($s['payload']['transaction_id'])) {
                $doc['payment'] = ['transaction_id' => $s['payload']['transaction_id'], 'label' => $s['payload']['payment_label'] ?? ''];
            }
        } elseif (!self::isTerminal($s['status']) && !self::isBusy($s['status'])) {
            // Relecture des CGV : page signée du module. Sinon reprise par un humain : le même panier, dans le navigateur.
            $doc['continue_url'] = $review
                ? $this->module->termsReviewUrl($s['id_session'], strtotime($s['expires_at']) - time())
                : $this->module->buildContinueUrl((int) $s['id_cart']);
        }

        return $doc;
    }

    public function cancelCheckout(array $s)
    {
        if (!in_array($s['status'], self::LOCKED, true)) {
            $s['status'] = 'canceled';
            if (!$this->save($s)) {
                $s = $this->load($s['id_session'], 'checkout');
            }
        }
        $busy = 'completed' === $s['status'] || self::isBusy($s['status']);

        return [200, $this->checkoutDoc($s, $busy ? [self::msg('not_modifiable', 'completed' === $s['status'] ? 'Commande déjà passée'
            : ('updating' === $s['status'] ? 'Mise à jour en cours' : 'Finalisation en cours'), 'unrecoverable')] : [])];
    }

    /** Messages des états terminaux internes : lien de reprise ouvert, commande impossible après débit. */
    private function terminalMessages(array $s)
    {
        if ('handed_off' === $s['status']) {
            return [self::msg('checkout_handed_off', 'L\'acheteur a repris ce panier sur la boutique (lien de reprise ouvert) : '
                . 'la session ne peut plus être finalisée par l\'API', 'unrecoverable')];
        }
        if ('failed' !== $s['status']) {
            return [];
        }
        $f = $s['payload']['failure'] ?? [];
        $m = [self::msg('order_failed', 'Le paiement a été accepté mais la commande n\'a pas pu être créée', 'unrecoverable')];
        if (!empty($f['unknown'])) {
            $m = [self::msg('order_failed', 'La finalisation a été interrompue avant la création de la commande', 'unrecoverable'),
                self::msg('payment_verification', 'Débit incertain : le marchand vérifie auprès du prestataire de paiement et rembourse si un débit a eu lieu', 'requires_buyer_input')];
        } elseif (!empty($f['refunded'])) {
            $m[] = ['type' => 'info', 'code' => 'payment_refunded', 'content' => 'Le paiement a été remboursé automatiquement'];
        } else {
            $m[] = self::msg('refund_pending', 'Remboursement automatique impossible : le marchand est alerté et doit rembourser', 'requires_buyer_input');
        }

        return $m;
    }

    public function completeCheckout(array $s, array $body)
    {
        if ('updating' === $s['status']) {
            return [409, $this->checkoutDoc($s, [self::msg('not_modifiable', 'Mise à jour de la session en cours : finalisation refusée, réessayer', 'recoverable')])];
        }
        if (self::isTerminal($s['status']) || self::isBusy($s['status'])) {
            return [200, $this->checkoutDoc($s)];
        }
        $cart = new Cart((int) $s['id_cart']);
        $this->bindContext($cart);
        $status = $this->computeStatus($s, $cart);
        if ('ready_for_complete' !== $status) {
            $s['status'] = $status;
            $this->save($s);

            return [200, $this->checkoutDoc($s)];
        }
        $instruments = $body['payment']['instruments'] ?? [];
        $instr = null;
        foreach ((array) $instruments as $i) {
            if (!$instr || !empty($i['selected'])) {
                $instr = $i;
            }
        }
        $handlerId = (string) ($instr['handler_id'] ?? '');
        $provider = '' !== $handlerId ? UcpBridgePaymentRegistry::get()->find($handlerId) : null;
        if (!$provider) {
            return [200, $this->checkoutDoc($s, [self::msg('payment_failed', 'Gestionnaire de paiement inconnu ou désactivé : ' . ($handlerId ?: '(aucun)'), 'recoverable', '$.payment.instruments[0].handler_id')])];
        }

        // Réservation atomique : une seule requête passe de ready_for_complete à complete_in_progress, quelles que
        // soient les clés d'idempotence. Les autres reçoivent l'état courant, sans débit ; une mise à jour en cours
        // (updating) fait refuser la finalisation en 409.
        if (!$this->reserve($s['id_session'], ['ready_for_complete', 'incomplete'], 'complete_in_progress')) {
            $cur = $this->load($s['id_session'], 'checkout') ?: $s;
            if ('updating' === $cur['status']) {
                return [409, $this->checkoutDoc($cur, [self::msg('not_modifiable', 'Mise à jour de la session en cours : finalisation refusée, réessayer', 'recoverable')])];
            }

            return [200, $this->checkoutDoc($cur)];
        }
        // État relu après la réservation : une mise à jour terminée entre la lecture et la réservation est prise en compte.
        $s = $this->load($s['id_session'], 'checkout');
        $this->charged = null;
        try {
            return $this->chargeAndOrder($s, $instr, $provider);
        } catch (Throwable $e) {
            $charged = $this->charged;
            $cur = null === $charged ? $this->load($s['id_session'], 'checkout') : null;
            if ($cur && 'complete_in_progress' === $cur['status'] && !empty($cur['payload']['charge_started_at'])) {
                // Exception pendant ou juste après l'appel au prestataire : débit possible, la session reste réservée.
                return $this->chargeUnknown($cur, $cur['payload']['charge_provider'] ?? '?', $e->getMessage());
            }
            if (null === $charged) {
                // Rien n'a été débité : la session redevient finalisable.
                unset($s['payload']['charge_started_at']);
                $s['status'] = 'ready_for_complete';
                $this->save($s, ['complete_in_progress']);
                throw $e;
            }

            return $this->orderFailedAfterCharge($s, $provider, $charged, $e->getMessage());
        }
    }

    /** @var array|null débit réussi pas encore couvert par une commande (à rembourser en cas d'échec) */
    private $charged;

    /** Sort de la réservation sans débit : statut recalculé, message à l'agent. */
    private function releaseWithoutCharge(array $s, Cart $cart, array $msgs)
    {
        unset($s['payload']['charge_started_at']);
        $s['status'] = 'incomplete';
        $s['status'] = $this->computeStatus($s, $cart);
        $this->save($s, ['complete_in_progress']);

        return [200, $this->checkoutDoc($s, $msgs)];
    }

    /**
     * Débit engagé au résultat inconnu (délai dépassé, connexion coupée, erreur 5xx, exception de l'adaptateur) : la
     * carte a pu être débitée. Jamais de retour à ready_for_complete : la session reste réservée avec la trace du débit
     * engagé (charge_started_at), et le rapprochement la classera en débit incertain, à vérifier chez le prestataire.
     */
    private function chargeUnknown(array $s, $providerName, $reason)
    {
        $s['payload']['charge_unknown'] = true;
        $this->save($s, ['complete_in_progress']);
        PrestaShopLogger::addLog('ucpbridge incident : session ' . $s['id_session'] . ', résultat du débit inconnu (' . $providerName
            . ', clé d\'idempotence ' . ($s['payload']['charge_idempotency_key'] ?? '?') . ') : ' . $reason
            . ' ; session laissée en finalisation, à rapprocher et à vérifier chez le prestataire', 3);

        return [200, $this->checkoutDoc($s)];
    }

    /** Lignes du panier PrestaShop cohérentes avec celles de la session (chaque ligne présente, quantité suffisante). */
    private function cartMatchesSession(array $s, Cart $cart)
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

    /** Dans la réservation : contrôles, débit, création de la commande. */
    private function chargeAndOrder(array $s, array $instr, UcpBridgePaymentProvider $provider)
    {
        $cart = new Cart((int) $s['id_cart']);
        $this->bindContext($cart);
        // Dernière revalidation des codes promo avant de débiter.
        $this->discounts()->sync($s, $cart, null, true);
        Cart::resetStaticCache();
        $cart = new Cart((int) $s['id_cart']);
        $this->bindContext($cart);
        $customer = new Customer((int) $cart->id_customer);

        // Contrôles avant débit : panier sans commande, client et clé de sécurité inchangés depuis la caisse.
        $incoherent = $cart->orderExists()
            || !(int) $cart->id_customer
            || (int) $cart->id_customer !== (int) ($s['payload']['id_customer'] ?? 0)
            || !Validate::isLoadedObject($customer)
            || !hash_equals((string) $customer->secure_key, (string) $cart->secure_key);
        if ($incoherent) {
            $s['status'] = 'handed_off';
            $this->save($s, ['complete_in_progress']);
            PrestaShopLogger::addLog('ucpbridge session ' . $s['id_session'] . ' : panier ' . (int) $cart->id
                . ' modifié hors API (commande existante ou client différent), finalisation refusée sans débit', 2);

            return [200, $this->checkoutDoc($s)];
        }
        // Session relue : encore complète ? Panier non vide et conforme aux lignes de la session ?
        if ($this->missing($s, $cart)) {
            return $this->releaseWithoutCharge($s, $cart, []);
        }
        $amount = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $cents = self::cents($amount);
        if ($cents <= 0 || !$this->cartMatchesSession($s, $cart)) {
            PrestaShopLogger::addLog('ucpbridge session ' . $s['id_session'] . ' : panier ' . (int) $cart->id
                . ' vide, à montant nul ou différent de la session, finalisation refusée sans débit', 2);

            return $this->releaseWithoutCharge($s, $cart, [self::msg('invalid', 'Panier vide, à montant nul ou modifié pendant la finalisation : aucun débit', 'recoverable', '$.line_items')]);
        }

        // Ce qui est présenté à l'acheteur au moment du paiement : base du journal de preuve.
        $presented = $this->checkoutDoc(['status' => 'ready_for_complete'] + $s);
        $this->context->customer = $customer;
        $currency = new Currency((int) $cart->id_currency);
        $idemKey = UcpBridgeIdempotency::chargeKey($s['id_session'], $instr, $cents, $currency->iso_code, (int) ($s['payload']['charge_attempt'] ?? 0));
        // Trace du débit engagé (lue par le rapprochement si le processus meurt pendant l'appel au prestataire).
        $s['payload']['charge_started_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $s['payload']['charge_idempotency_key'] = $idemKey;
        $s['payload']['charge_provider'] = $provider->getName();
        $this->save($s, ['complete_in_progress']);
        $pay = $provider->charge((array) ($instr['credential'] ?? []), $cents, $currency->iso_code, [
            'instrument' => $instr,
            'id_cart' => (int) $cart->id,
            'session_id' => $s['id_session'],
            // Session, tentative, instrument, montant et devise : ni une autre carte, ni un panier modifié, ni un nouvel
            // essai après un refus n'héritent de la réponse précédente du prestataire.
            'idempotency_key' => $idemKey,
            'buyer' => $s['payload']['buyer'] ?? [],
            'continue_url' => $this->module->buildContinueUrl((int) $cart->id),
        ]);
        if (UcpBridgePaymentResult::SUCCEEDED === $pay->status) {
            // Débit mémorisé AVANT toute écriture : une erreur ensuite ne fait jamais croire à « rien de débité ».
            $this->charged = ['transaction_id' => $pay->transactionId, 'amount' => $cents, 'currency' => $currency->iso_code];
        }
        $this->journalPayment($s['id_session'], $provider->getName(), 'charge', $pay->status, $pay->transactionId, $cents, $currency->iso_code, $pay->message);
        if (UcpBridgePaymentResult::REQUIRES_ACTION === $pay->status) {
            unset($s['payload']['charge_started_at']);
            $s['status'] = 'requires_escalation';
            $s['payload']['escalation_url'] = $pay->actionUrl;
            $this->save($s, ['complete_in_progress']);

            return [200, $this->checkoutDoc($s)];
        }
        if (UcpBridgePaymentResult::UNKNOWN === $pay->status) {
            return $this->chargeUnknown($s, $provider->getName(), $pay->message);
        }
        if (UcpBridgePaymentResult::SUCCEEDED !== $pay->status) {
            // Refus du prestataire, aucun débit : la session redevient finalisable avec un autre moyen.
            unset($s['payload']['charge_started_at']);
            // Refus définitif : le prochain essai, même avec la même carte, part avec une nouvelle clé d'idempotence.
            $s['payload']['charge_attempt'] = (int) ($s['payload']['charge_attempt'] ?? 0) + 1;
            $s['status'] = 'ready_for_complete';
            $this->save($s, ['complete_in_progress']);

            return [200, $this->checkoutDoc($s, [self::msg('payment_failed', $pay->message, 'recoverable', '$.payment.instruments[0].credential')])];
        }
        $charged = $this->charged;
        $error = null;
        try {
            $this->module->validateOrder((int) $cart->id, (int) Configuration::get('PS_OS_PAYMENT'), $amount, $pay->label,
                'Commande UCP, session ' . $s['id_session'] . ', ' . $provider->getName() . ' ' . $pay->transactionId,
                ['transaction_id' => $pay->transactionId], (int) $cart->id_currency, false, $customer->secure_key);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        // validateOrder crée la commande PUIS exécute les hooks (modules tiers) et les e-mails : une exception peut
        // survenir alors que la commande existe. On la cherche avant de conclure à un échec.
        $idOrder = (int) $this->module->currentOrder ?: (int) Order::getIdByCartId((int) $cart->id);
        if (!$idOrder) {
            return $this->orderFailedAfterCharge($s, $provider, $charged, $error ?: 'validateOrder sans commande');
        }
        if (null !== $error) {
            PrestaShopLogger::addLog('ucpbridge session ' . $s['id_session'] . ' : exception après création de la commande ' . $idOrder
                . ' (' . $error . ') ; commande conservée, aucun remboursement', 2);
        }
        $s = $this->markCompleted($s, $idOrder, $pay->transactionId, $pay->label);
        $this->charged = null; // la commande existe : plus rien à rembourser
        try {
            $consent = $s['payload']['consent'] ?? [];
            $this->applyConsent((int) $customer->id, $consent);
            UcpBridgeProof::record([
                'id_order' => $s['id_order'],
                'id_cart' => (int) $cart->id,
                'session_id' => $s['id_session'],
                'agent' => $this->agent,
                'caller' => $this->owner,
                'api_ip' => (string) Tools::getRemoteAddr(),
                'api_user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'terms_version' => $this->module->termsInfo()['version'],
                'terms_acceptance' => $s['payload']['terms_acceptance'] ?? null,
                'links' => $presented['links'] ?? [],
                'disclosures' => array_values(array_filter($presented['messages'] ?? [], function ($m) {
                    return 'disclosure' === ($m['presentation'] ?? '');
                })),
                'totals' => $presented['totals'] ?? [],
                'consent' => UcpBridgeConsent::journal($consent),
            ]);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('ucpbridge journal de preuve de la commande ' . (int) $s['id_order'] . ' : ' . $e->getMessage(), 3);
        }

        return [200, $this->checkoutDoc($s)];
    }

    /** Session terminée par une commande existante (finalisation normale, exception tardive ou rapprochement). */
    private function markCompleted(array $s, $idOrder, $transactionId, $label)
    {
        $s['id_order'] = (int) $idOrder;
        $s['order_ref'] = 'ord_' . bin2hex(random_bytes(12));
        $s['payload']['transaction_id'] = (string) $transactionId;
        $s['payload']['payment_label'] = (string) $label;
        unset($s['payload']['charge_started_at']);
        $s['status'] = 'completed';
        $this->save($s, ['complete_in_progress', 'reconciling']);

        return $s;
    }

    /**
     * Débit réussi mais commande absente : état terminal (jamais de retour à ready_for_complete), incident
     * journalisé, remboursement automatique par l'adaptateur. La réponse est enregistrée par l'idempotence.
     * Si une commande existe malgré tout sur le panier, rien n'est remboursé et la session est terminée par elle.
     */
    private function orderFailedAfterCharge(array $s, UcpBridgePaymentProvider $provider, array $charged, $reason, $origin = 'finalisation')
    {
        $idOrder = (int) Order::getIdByCartId((int) $s['id_cart']);
        if ($idOrder) {
            PrestaShopLogger::addLog('ucpbridge session ' . $s['id_session'] . ' (' . $origin . ') : commande ' . $idOrder
                . ' trouvée après « ' . $reason . ' » ; commande conservée, aucun remboursement', 2);
            $s = $this->markCompleted($s, $idOrder, $charged['transaction_id'], $s['payload']['payment_label'] ?? $provider->getName());

            return [200, $this->checkoutDoc($s)];
        }
        $refund = null;
        try {
            $refund = $provider->refund($charged['transaction_id'], $charged['amount'], $charged['currency'], ['session_id' => $s['id_session']]);
        } catch (Throwable $e) {
            $refund = UcpBridgePaymentResult::fail($e->getMessage());
        }
        $ok = $refund && UcpBridgePaymentResult::SUCCEEDED === $refund->status;
        $this->journalPayment($s['id_session'], $provider->getName(), 'refund', $ok ? 'succeeded' : 'failed',
            $refund ? $refund->transactionId : '', $charged['amount'], $charged['currency'], $refund ? $refund->message : '');
        PrestaShopLogger::addLog('ucpbridge incident (' . $origin . ') : session ' . $s['id_session'] . ', débit ' . $charged['transaction_id']
            . ' (' . $provider->getName() . ', ' . $charged['amount'] . ' centimes) réussi mais commande non créée : ' . $reason . ' ; remboursement '
            . ($ok ? 'effectué ' . $refund->transactionId : 'IMPOSSIBLE, à traiter à la main : ' . ($refund ? $refund->message : '')), 4);
        unset($s['payload']['charge_started_at']);
        $s['status'] = 'failed';
        $s['payload']['failure'] = ['transaction_id' => $charged['transaction_id'], 'refunded' => $ok,
            'refund_id' => $ok ? $refund->transactionId : '', 'at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->save($s, ['complete_in_progress', 'reconciling']);

        return [200, $this->checkoutDoc($s)];
    }

    /**
     * Écriture au journal des paiements qui ne fait jamais échouer la caisse : un débit ou un remboursement a déjà eu
     * lieu chez le prestataire, une erreur de base de données ne doit ni l'effacer ni interrompre la commande. L'échec
     * est signalé au journal PrestaShop avec la référence de l'opération.
     */
    private function journalPayment($sessionId, $providerName, $operation, $status, $transactionId, $amount, $currency, $message = '')
    {
        $error = 'insertion refusée';
        try {
            if (UcpBridgeProof::payment($sessionId, $providerName, $operation, $status, $transactionId, $amount, $currency, $message)) {
                return;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        PrestaShopLogger::addLog('ucpbridge journal des paiements indisponible : session ' . $sessionId . ', ' . $operation . ' ' . $status
            . ' ' . $transactionId . ' (' . $providerName . ', ' . (int) $amount . ' centimes ' . $currency . ') non journalisé : '
            . substr(strip_tags((string) $error), 0, 300), 3);
    }

    /* ========================== Rapprochement des sessions bloquées ========================== */

    /**
     * Sessions de caisse restées réservées plus de $minutes minutes (processus PHP tué, erreur fatale, délai
     * d'exécution dépassé) : commande trouvée = session terminée par elle ; débit réussi sans commande = remboursement
     * et incident ; débit engagé sans résultat connu = incident à vérifier chez le prestataire ; rien d'engagé =
     * session rendue finalisable. Lancé par bin/reconcile.php (ligne de commande, tâche planifiée) ou par le bouton
     * de la configuration du module.
     *
     * @return array liste de [id_session, action]
     */
    public function reconcile($minutes = 15)
    {
        $before = date('Y-m-d H:i:s', time() - 60 * max(1, (int) $minutes));
        $rows = Db::getInstance()->executeS('SELECT id_session, status FROM `' . _DB_PREFIX_ . 'ucpbridge_session`
            WHERE kind = \'checkout\' AND status IN (\'complete_in_progress\', \'reconciling\', \'updating\')
            AND date_upd < \'' . pSQL($before) . '\' ORDER BY date_upd') ?: [];
        $done = [];
        foreach ($rows as $r) {
            $id = (string) $r['id_session'];
            if (!$this->reserve($id, [$r['status']], 'reconciling', $before)) {
                continue; // repris entre-temps par un autre processus
            }
            $s = $this->load($id, 'checkout');
            try {
                $done[] = [$id, $this->reconcileOne($s, $r['status'])];
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('ucpbridge rapprochement : session ' . $id . ' : ' . $e->getMessage(), 3);
                $done[] = [$id, 'erreur : ' . $e->getMessage()];
            }
        }

        return $done;
    }

    private function reconcileOne(array $s, $was)
    {
        $id = $s['id_session'];
        $cart = new Cart((int) $s['id_cart']);
        $this->bindContext($cart);
        if ('updating' === $was) {
            // Mise à jour interrompue : le panier reflète ce qui a été écrit, la session redevient modifiable.
            $s['status'] = !empty($s['payload']['escalation_url']) ? 'requires_escalation' : 'incomplete';
            $s['status'] = $this->computeStatus($s, $cart);
            $this->save($s, ['reconciling']);
            PrestaShopLogger::addLog('ucpbridge rapprochement : session ' . $id . ', mise à jour interrompue, session rendue (' . $s['status'] . ')', 2);

            return 'mise à jour interrompue, session rendue';
        }
        $charge = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ucpbridge_payment` WHERE session_id = \'' . pSQL($id) . '\'
            AND operation = \'charge\' AND status = \'succeeded\' ORDER BY id_payment DESC');
        $refunded = $charge && Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'ucpbridge_payment` WHERE session_id = \'' . pSQL($id) . '\'
            AND operation = \'refund\' AND id_payment > ' . (int) $charge['id_payment']);
        $idOrder = (int) Order::getIdByCartId((int) $s['id_cart']);
        if ($idOrder) {
            PrestaShopLogger::addLog('ucpbridge rapprochement : session ' . $id . ' bloquée, commande ' . $idOrder
                . ' trouvée sur le panier : session terminée, aucun remboursement', 2);
            $this->markCompleted($s, $idOrder, $charge ? $charge['transaction_id'] : '', $charge ? $charge['provider'] : '');

            return 'commande ' . $idOrder . ' trouvée, session terminée';
        }
        if ($charge && !$refunded) {
            $providers = UcpBridgePaymentRegistry::get()->all();
            $provider = $providers[$charge['provider']] ?? null;
            $charged = ['transaction_id' => $charge['transaction_id'], 'amount' => (int) $charge['amount'], 'currency' => $charge['currency']];
            if ($provider) {
                $this->orderFailedAfterCharge($s, $provider, $charged, 'processus interrompu après le débit (rapprochement)', 'rapprochement');
                $f = $this->load($id, 'checkout')['payload']['failure'] ?? [];

                return !empty($f['refunded']) ? 'débit sans commande, remboursé' : 'débit sans commande, remboursement IMPOSSIBLE';
            }
            PrestaShopLogger::addLog('ucpbridge incident (rapprochement) : session ' . $id . ', débit ' . $charge['transaction_id']
                . ' sans commande, adaptateur ' . $charge['provider'] . ' introuvable : remboursement à faire à la main', 4);
            $s['status'] = 'failed';
            $s['payload']['failure'] = ['transaction_id' => $charge['transaction_id'], 'refunded' => false, 'refund_id' => '', 'at' => gmdate('Y-m-d\TH:i:s\Z')];
            $this->save($s, ['reconciling']);

            return 'débit sans commande, adaptateur introuvable';
        }
        if (!empty($s['payload']['charge_started_at'])) {
            // Le processus est mort pendant l'appel au prestataire : résultat inconnu, aucune décision automatique.
            PrestaShopLogger::addLog('ucpbridge incident (rapprochement) : session ' . $id . ', débit engagé le '
                . $s['payload']['charge_started_at'] . ' sans résultat connu (' . ($s['payload']['charge_provider'] ?? '?') . ', clé d\'idempotence '
                . ($s['payload']['charge_idempotency_key'] ?? '?') . ') : vérifier chez le prestataire et rembourser si besoin', 4);
            $s['status'] = 'failed';
            $s['payload']['failure'] = ['transaction_id' => '', 'refunded' => false, 'unknown' => true, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
            $this->save($s, ['reconciling']);

            return 'débit incertain, à vérifier chez le prestataire';
        }
        // Réservation sans débit engagé : la session redevient finalisable.
        $s['status'] = 'incomplete';
        $s['status'] = $this->computeStatus($s, $cart);
        $this->save($s, ['reconciling']);
        PrestaShopLogger::addLog('ucpbridge rapprochement : session ' . $id . ' bloquée sans débit, rendue (' . $s['status'] . ')', 1);

        return 'aucun débit, session rendue (' . $s['status'] . ')';
    }

    /** Options newsletter et partenaires du client PrestaShop, uniquement sur consentement explicite. */
    private function applyConsent($idCustomer, array $consent)
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

    private function orderPermalink($idOrder)
    {
        $o = new Order((int) $idOrder);

        return $this->context->link->getPageLink('guest-tracking', true, null, ['order_reference' => $o->reference]);
    }

    /* ================================== Commandes ================================== */

    /** @return array [code HTTP, document] */
    public function orderDoc($ref)
    {
        $s = $this->loadByOrderRef($ref);
        $caps = ['dev.ucp.shopping.order'];
        if (!$s || !$this->ownedByCaller($s)) {
            return [404, $this->errorDoc([self::msg('not_found', 'Commande introuvable', 'unrecoverable')], $caps)];
        }
        $o = new Order((int) $s['id_order']);
        $lines = [];
        $byKey = [];
        foreach ($s['payload']['lines'] as $l) {
            $byKey[$l['id_product'] . '-' . $l['id_product_attribute']] = $l;
        }
        foreach ($o->getOrderDetailList() as $d) {
            $key = (int) $d['product_id'] . '-' . (int) $d['product_attribute_id'];
            $tot = self::cents($d['total_price_tax_incl']);
            $lines[] = [
                'id' => $byKey[$key]['id'] ?? ('li_' . $d['id_order_detail']),
                'item' => [
                    'id' => $byKey[$key]['requested_id'] ?? $key,
                    'title' => $d['product_name'],
                    'price' => self::cents($d['unit_price_tax_incl']),
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
        $totals = UcpBridgeTotals::order(self::cents($o->total_products), self::cents($o->total_shipping_tax_excl),
            self::cents($o->total_discounts_tax_excl), self::cents($o->total_paid_tax_incl));

        return [200, [
            'ucp' => ['version' => Ucpbridge::UCP_VERSION, 'status' => 'success', 'capabilities' => ['dev.ucp.shopping.order' => [['version' => Ucpbridge::UCP_VERSION]]]],
            'id' => (string) $s['order_ref'],
            'reference' => $o->reference,
            'checkout_id' => $s['id_session'],
            'permalink_url' => $this->orderPermalink((int) $o->id),
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
