<?php
/**
 * Finalisation d'une session de caisse (complete_checkout) : réservation atomique, contrôles avant débit, débit par
 * l'adaptateur de paiement, création de la commande, journal des paiements et journal de preuve. Débit au résultat
 * inconnu, commande impossible après débit (remboursement) et fin par une commande existante.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCheckoutCompletion
{
    /** @var Context */
    private $context;
    /** @var Ucpbridge */
    private $module;
    /** @var UcpBridgeCaller */
    private $caller;
    /** @var UcpBridgeSessionStore */
    private $store;
    /** @var UcpBridgeCartLines */
    private $lines;
    /** @var UcpBridgeBuyer */
    private $buyer;
    /** @var UcpBridgeCheckoutStatus */
    private $status;
    /** @var UcpBridgeCheckoutView */
    private $view;
    /** @var array|null débit réussi pas encore couvert par une commande (à rembourser en cas d'échec) */
    private $charged;

    public function __construct(Context $context, Ucpbridge $module, UcpBridgeCaller $caller, UcpBridgeSessionStore $store,
        UcpBridgeCartLines $lines, UcpBridgeBuyer $buyer, UcpBridgeCheckoutStatus $status, UcpBridgeCheckoutView $view)
    {
        $this->context = $context;
        $this->module = $module;
        $this->caller = $caller;
        $this->store = $store;
        $this->lines = $lines;
        $this->buyer = $buyer;
        $this->status = $status;
        $this->view = $view;
    }

    public function completeCheckout(array $s, array $body)
    {
        if ('updating' === $s['status']) {
            return [409, $this->view->checkoutDoc($s, [UcpBridgeMessage::error('not_modifiable', UcpBridgeI18n::api('Session update in progress: completion refused, try again'), 'recoverable')])];
        }
        if (UcpBridgeSessionStore::isTerminal($s['status']) || UcpBridgeSessionStore::isBusy($s['status'])) {
            return [200, $this->view->checkoutDoc($s)];
        }
        $cart = new Cart((int) $s['id_cart']);
        $this->lines->bindContext($cart);
        $status = $this->status->computeStatus($s, $cart);
        if ('ready_for_complete' !== $status) {
            $s['status'] = $status;
            $this->store->save($s);

            return [200, $this->view->checkoutDoc($s)];
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
            return [200, $this->view->checkoutDoc($s, [UcpBridgeMessage::error('payment_failed', UcpBridgeI18n::api('Unknown or disabled payment handler: %handler%', ['%handler%' => $handlerId ?: UcpBridgeI18n::api('(none)')]), 'recoverable', '$.payment.instruments[0].handler_id')])];
        }

        // Réservation atomique : une seule requête passe de ready_for_complete à complete_in_progress, quelles que
        // soient les clés d'idempotence. Les autres reçoivent l'état courant, sans débit ; une mise à jour en cours
        // (updating) fait refuser la finalisation en 409.
        if (!$this->store->reserve($s['id_session'], ['ready_for_complete', 'incomplete'], 'complete_in_progress')) {
            $cur = $this->store->load($s['id_session'], 'checkout') ?: $s;
            if ('updating' === $cur['status']) {
                return [409, $this->view->checkoutDoc($cur, [UcpBridgeMessage::error('not_modifiable', UcpBridgeI18n::api('Session update in progress: completion refused, try again'), 'recoverable')])];
            }

            return [200, $this->view->checkoutDoc($cur)];
        }
        // État relu après la réservation : une mise à jour terminée entre la lecture et la réservation est prise en compte.
        $s = $this->store->load($s['id_session'], 'checkout');
        $this->charged = null;
        try {
            return $this->chargeAndOrder($s, $instr, $provider);
        } catch (Throwable $e) {
            $charged = $this->charged;
            $cur = null === $charged ? $this->store->load($s['id_session'], 'checkout') : null;
            if ($cur && 'complete_in_progress' === $cur['status'] && !empty($cur['payload']['charge_started_at'])) {
                // Exception pendant ou juste après l'appel au prestataire : débit possible, la session reste réservée.
                return $this->chargeUnknown($cur, $cur['payload']['charge_provider'] ?? '?', $e->getMessage());
            }
            if (null === $charged) {
                // Rien n'a été débité : la session redevient finalisable.
                unset($s['payload']['charge_started_at']);
                $s['status'] = 'ready_for_complete';
                $this->store->save($s, ['complete_in_progress']);
                throw $e;
            }

            return $this->orderFailedAfterCharge($s, $provider, $charged, $e->getMessage());
        }
    }

    /** Sort de la réservation sans débit : statut recalculé, message à l'agent. */
    private function releaseWithoutCharge(array $s, Cart $cart, array $msgs)
    {
        unset($s['payload']['charge_started_at']);
        $s['status'] = 'incomplete';
        $s['status'] = $this->status->computeStatus($s, $cart);
        $this->store->save($s, ['complete_in_progress']);

        return [200, $this->view->checkoutDoc($s, $msgs)];
    }

    /**
     * Débit engagé au résultat inconnu (délai dépassé, connexion coupée, erreur 5xx, exception de l'adaptateur) : la
     * carte a pu être débitée. Jamais de retour à ready_for_complete : la session reste réservée avec la trace du débit
     * engagé (charge_started_at), et le rapprochement la classera en débit incertain, à vérifier chez le prestataire.
     */
    private function chargeUnknown(array $s, $providerName, $reason)
    {
        $s['payload']['charge_unknown'] = true;
        $this->store->save($s, ['complete_in_progress']);
        PrestaShopLogger::addLog('ucpbridge incident : session ' . $s['id_session'] . ', résultat du débit inconnu (' . $providerName
            . ', clé d\'idempotence ' . ($s['payload']['charge_idempotency_key'] ?? '?') . ') : ' . $reason
            . ' ; session laissée en finalisation, à rapprocher et à vérifier chez le prestataire', 3);

        return [200, $this->view->checkoutDoc($s)];
    }

    /** Dans la réservation : contrôles, débit, création de la commande. */
    private function chargeAndOrder(array $s, array $instr, UcpBridgePaymentProvider $provider)
    {
        $cart = new Cart((int) $s['id_cart']);
        $this->lines->bindContext($cart);
        // Dernière revalidation des codes promo avant de débiter.
        (new UcpBridgeDiscount($this->context))->sync($s, $cart, null, true);
        Cart::resetStaticCache();
        $cart = new Cart((int) $s['id_cart']);
        $this->lines->bindContext($cart);
        $customer = new Customer((int) $cart->id_customer);

        // Contrôles avant débit : panier sans commande, client et clé de sécurité inchangés depuis la caisse.
        $incoherent = $cart->orderExists()
            || !(int) $cart->id_customer
            || (int) $cart->id_customer !== (int) ($s['payload']['id_customer'] ?? 0)
            || !Validate::isLoadedObject($customer)
            || !hash_equals((string) $customer->secure_key, (string) $cart->secure_key);
        if ($incoherent) {
            $s['status'] = 'handed_off';
            $this->store->save($s, ['complete_in_progress']);
            PrestaShopLogger::addLog('ucpbridge session ' . $s['id_session'] . ' : panier ' . (int) $cart->id
                . ' modifié hors API (commande existante ou client différent), finalisation refusée sans débit', 2);

            return [200, $this->view->checkoutDoc($s)];
        }
        // Session relue : encore complète ? Panier non vide et conforme aux lignes de la session ?
        if ($this->status->missing($s, $cart)) {
            return $this->releaseWithoutCharge($s, $cart, []);
        }
        $amount = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $cents = UcpBridgeCartLines::cents($amount);
        if ($cents <= 0 || !$this->lines->cartMatchesSession($s, $cart)) {
            PrestaShopLogger::addLog('ucpbridge session ' . $s['id_session'] . ' : panier ' . (int) $cart->id
                . ' vide, à montant nul ou différent de la session, finalisation refusée sans débit', 2);

            return $this->releaseWithoutCharge($s, $cart, [UcpBridgeMessage::error('invalid', UcpBridgeI18n::api('Cart empty, with a zero amount or modified during completion: no charge'), 'recoverable', '$.line_items')]);
        }

        // Ce qui est présenté à l'acheteur au moment du paiement : base du journal de preuve.
        $presented = $this->view->checkoutDoc(['status' => 'ready_for_complete'] + $s);
        $this->context->customer = $customer;
        $currency = new Currency((int) $cart->id_currency);
        $idemKey = UcpBridgeIdempotency::chargeKey($s['id_session'], $instr, $cents, $currency->iso_code, (int) ($s['payload']['charge_attempt'] ?? 0));
        // Trace du débit engagé (lue par le rapprochement si le processus meurt pendant l'appel au prestataire).
        $s['payload']['charge_started_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $s['payload']['charge_idempotency_key'] = $idemKey;
        $s['payload']['charge_provider'] = $provider->getName();
        $this->store->save($s, ['complete_in_progress']);
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
            $this->store->save($s, ['complete_in_progress']);

            return [200, $this->view->checkoutDoc($s)];
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
            $this->store->save($s, ['complete_in_progress']);

            return [200, $this->view->checkoutDoc($s, [UcpBridgeMessage::error('payment_failed', $pay->message, 'recoverable', '$.payment.instruments[0].credential')])];
        }
        $charged = $this->charged;
        $error = null;
        try {
            $this->module->validateOrder((int) $cart->id, (int) Configuration::get('PS_OS_PAYMENT'), $amount, $pay->label,
                UcpBridgeI18n::admin('UCP order, session %session%, %provider% %transaction%', ['%session%' => $s['id_session'], '%provider%' => $provider->getName(), '%transaction%' => $pay->transactionId]),
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
            $this->buyer->applyConsent((int) $customer->id, $consent);
            UcpBridgeProof::record([
                'id_order' => $s['id_order'],
                'id_cart' => (int) $cart->id,
                'session_id' => $s['id_session'],
                'agent' => $this->caller->agent,
                'caller' => $this->caller->owner,
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

        return [200, $this->view->checkoutDoc($s)];
    }

    /** Session terminée par une commande existante (finalisation normale, exception tardive ou rapprochement). */
    public function markCompleted(array $s, $idOrder, $transactionId, $label)
    {
        $s['id_order'] = (int) $idOrder;
        $s['order_ref'] = 'ord_' . bin2hex(random_bytes(12));
        $s['payload']['transaction_id'] = (string) $transactionId;
        $s['payload']['payment_label'] = (string) $label;
        unset($s['payload']['charge_started_at']);
        $s['status'] = 'completed';
        $this->store->save($s, ['complete_in_progress', 'reconciling']);

        return $s;
    }

    /**
     * Débit réussi mais commande absente : état terminal (jamais de retour à ready_for_complete), incident
     * journalisé, remboursement automatique par l'adaptateur. La réponse est enregistrée par l'idempotence.
     * Si une commande existe malgré tout sur le panier, rien n'est remboursé et la session est terminée par elle.
     */
    public function orderFailedAfterCharge(array $s, UcpBridgePaymentProvider $provider, array $charged, $reason, $origin = 'finalisation')
    {
        $idOrder = (int) Order::getIdByCartId((int) $s['id_cart']);
        if ($idOrder) {
            PrestaShopLogger::addLog('ucpbridge session ' . $s['id_session'] . ' (' . $origin . ') : commande ' . $idOrder
                . ' trouvée après « ' . $reason . ' » ; commande conservée, aucun remboursement', 2);
            $s = $this->markCompleted($s, $idOrder, $charged['transaction_id'], $s['payload']['payment_label'] ?? $provider->getName());

            return [200, $this->view->checkoutDoc($s)];
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
        $this->store->save($s, ['complete_in_progress', 'reconciling']);

        return [200, $this->view->checkoutDoc($s)];
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
}
