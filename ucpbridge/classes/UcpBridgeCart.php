<?php
/**
 * Coeur métier : panier UCP transférable (dev.ucp.shopping.cart), caisse native
 * (dev.ucp.shopping.checkout + fulfillment), commande (dev.ucp.shopping.order).
 * Chaque session UCP s'appuie sur un vrai panier PrestaShop (ps_cart) : prix, TVA,
 * transporteurs et stock sont ceux de la boutique, sans recalcul parallèle.
 *
 * Point d'entrée unique (contrôleurs, MCP, ligne de commande) : assemble les classes de classes/cart/ autour d'un même
 * appelant et d'un même stockage, et leur délègue chaque opération.
 * - UcpBridgeCaller, UcpBridgeSessionStore : identité de l'appelant, sessions et statuts ;
 * - UcpBridgeCartLines, UcpBridgeBuyer, UcpBridgeFulfillment : lignes et panier PrestaShop, acheteur, livraison ;
 * - UcpBridgeCartSession, UcpBridgeCheckoutSession : panier transférable, session de caisse ;
 * - UcpBridgeCheckoutStatus, UcpBridgeCheckoutView, UcpBridgeOrderView : statut et documents UCP ;
 * - UcpBridgeCheckoutCompletion, UcpBridgeReconciler : finalisation et paiement, rapprochement des sessions bloquées ;
 * - UcpBridgeMessage : messages et enveloppes UCP.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCart
{
    const TTL = 86400;
    /** Statuts : voir UcpBridgeSessionStore (constantes reprises ici pour les appelants existants). */
    const LOCKED = UcpBridgeSessionStore::LOCKED;
    const TERMINAL = UcpBridgeSessionStore::TERMINAL;
    const BUSY = UcpBridgeSessionStore::BUSY;
    const EDITABLE = UcpBridgeSessionStore::EDITABLE;

    /** @var Context */
    private $context;
    /** @var UcpBridgeCatalog */
    private $catalog;
    /** @var UcpBridgeCaller */
    private $caller;
    /** @var UcpBridgeSessionStore */
    private $store;
    /** @var UcpBridgeCartLines */
    private $lines;
    /** @var UcpBridgeCheckoutStatus */
    private $status;
    /** @var UcpBridgeCheckoutView */
    private $view;
    /** @var UcpBridgeCartSession */
    private $carts;
    /** @var UcpBridgeCheckoutSession */
    private $checkouts;
    /** @var UcpBridgeCheckoutCompletion */
    private $completion;

    public function __construct(Context $context, Ucpbridge $module)
    {
        $this->context = $context;
        $this->catalog = new UcpBridgeCatalog($context);
        $this->caller = new UcpBridgeCaller();
        $this->store = new UcpBridgeSessionStore($this->caller);
        $this->lines = new UcpBridgeCartLines($context, $this->catalog);
        $buyer = new UcpBridgeBuyer($context, $this->caller);
        $fulfillment = new UcpBridgeFulfillment($context, $this->lines);
        $this->status = new UcpBridgeCheckoutStatus($module, $this->catalog, $fulfillment);
        $this->view = new UcpBridgeCheckoutView($context, $module, $this->lines, $fulfillment, $this->status);
        $this->carts = new UcpBridgeCartSession($context, $module, $this->caller, $this->store, $this->catalog, $this->lines, $this->view);
        $this->checkouts = new UcpBridgeCheckoutSession($context, $module, $this->caller, $this->store, $this->lines, $buyer, $fulfillment,
            $this->status, $this->view);
        $this->completion = new UcpBridgeCheckoutCompletion($context, $module, $this->caller, $this->store, $this->lines, $buyer,
            $this->status, $this->view);
    }

    public function catalog()
    {
        return $this->catalog;
    }

    /** Gestionnaires annoncés : ceux des adaptateurs inscrits au registre (module + modules tiers). */
    public static function paymentHandlers()
    {
        return UcpBridgePaymentRegistry::get()->handlers();
    }

    /* ====================== Appelant (identity linking, plateforme) ====================== */

    public function setUser($idCustomer)
    {
        $this->caller->idUser = (int) $idCustomer;
    }

    public function setAgent($agent)
    {
        $this->caller->agent = substr(trim((string) $agent), 0, 512);
    }

    public function setCaller($owner)
    {
        $this->caller->owner = (string) $owner;
    }

    /** Cloisonnement strict par appelant (voir UcpBridgeCaller::owns). */
    public function ownedByCaller(array $s)
    {
        return $this->caller->owns($s);
    }

    public static function isTerminal($status)
    {
        return UcpBridgeSessionStore::isTerminal($status);
    }

    public static function isBusy($status)
    {
        return UcpBridgeSessionStore::isBusy($status);
    }

    public static function exposedStatus($status)
    {
        return UcpBridgeSessionStore::exposedStatus($status);
    }

    /* ============================ Sessions et lignes ============================ */

    public function load($id, $kind)
    {
        return $this->store->load($id, $kind);
    }

    public function loadByOrderRef($ref)
    {
        return $this->store->loadByOrderRef($ref);
    }

    /** @return array [lignes résolues, messages d'erreur] */
    public function resolveLines($lineItems)
    {
        return $this->lines->resolveLines($lineItems);
    }

    public function errorDoc(array $messages, array $capabilities)
    {
        return UcpBridgeMessage::errorDoc($messages, $capabilities, Ucpbridge::UCP_VERSION, $this->context->link->getPageLink('index', true));
    }

    public function discounts()
    {
        return new UcpBridgeDiscount($this->context);
    }

    /* ======================== Paniers (voir UcpBridgeCartSession) ======================== */

    public function createCart(array $body)
    {
        return $this->carts->createCart($body);
    }

    /** Détail d'un produit (get_product) : produit et toutes ses variantes. */
    public function productDoc($id)
    {
        return $this->carts->productDoc($id);
    }

    public function cartDoc(array $s, $msgs = null)
    {
        return $this->carts->cartDoc($s, $msgs);
    }

    public function updateCart(array $s, array $body)
    {
        return $this->carts->updateCart($s, $body);
    }

    public function cancelCart(array $s)
    {
        return $this->carts->cancelCart($s);
    }

    /* ================= Caisse native (voir UcpBridgeCheckoutSession, UcpBridgeCheckoutView) ================= */

    public function createCheckout(array $body)
    {
        return $this->checkouts->createCheckout($body);
    }

    public function updateCheckout(array $s, array $body)
    {
        return $this->checkouts->updateCheckout($s, $body);
    }

    /** Acceptation des CGV sur la page du module : date, version, IP ; la session redevient finalisable. */
    public function acceptTerms(array $s, $ip, $userAgent)
    {
        return $this->checkouts->acceptTerms($s, $ip, $userAgent);
    }

    public function cancelCheckout(array $s)
    {
        return $this->checkouts->cancelCheckout($s);
    }

    public function checkoutDoc(array $s, array $extraMsgs = [])
    {
        return $this->view->checkoutDoc($s, $extraMsgs);
    }

    /** Finalisation : voir UcpBridgeCheckoutCompletion. */
    public function completeCheckout(array $s, array $body)
    {
        return $this->completion->completeCheckout($s, $body);
    }

    /**
     * Rapprochement des sessions bloquées depuis plus de $minutes minutes (voir UcpBridgeReconciler).
     *
     * @return array liste de [id_session, action]
     */
    public function reconcile($minutes = 15)
    {
        return (new UcpBridgeReconciler($this->store, $this->lines, $this->status, $this->completion))->reconcile($minutes);
    }

    /* ================================== Commandes ================================== */

    /** @return array [code HTTP, document] */
    public function orderDoc($ref)
    {
        return (new UcpBridgeOrderView($this->context, $this->caller, $this->store, $this->view))->orderDoc($ref);
    }
}
