<?php
/**
 * Rapprochement des sessions de caisse bloquées (processus interrompu pendant une finalisation, une mise à jour ou un
 * rapprochement) : commande trouvée, débit sans commande, débit incertain ou rien d'engagé.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeReconciler
{
    /** @var UcpBridgeSessionStore */
    private $store;
    /** @var UcpBridgeCartLines */
    private $lines;
    /** @var UcpBridgeCheckoutStatus */
    private $status;
    /** @var UcpBridgeCheckoutCompletion */
    private $completion;

    public function __construct(UcpBridgeSessionStore $store, UcpBridgeCartLines $lines, UcpBridgeCheckoutStatus $status,
        UcpBridgeCheckoutCompletion $completion)
    {
        $this->store = $store;
        $this->lines = $lines;
        $this->status = $status;
        $this->completion = $completion;
    }

    /**
     * Sessions de caisse restées réservées plus de $minutes minutes (processus PHP tué, erreur fatale, délai
     * d'exécution dépassé) : commande trouvée = session terminée par elle ; débit réussi sans commande = remboursement
     * et incident ; débit engagé sans résultat connu = incident à vérifier chez le prestataire ; rien d'engagé =
     * session rendue finalisable. Lancé par bin/reconcile.php (ligne de commande, tâche planifiée) ou par le bouton
     * de la configuration du module.
     *
     * @return array liste de [id_session, action lisible, code stable de l'action]
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
            if (!$this->store->reserve($id, [$r['status']], 'reconciling', $before)) {
                continue; // repris entre-temps par un autre processus
            }
            $s = $this->store->load($id, 'checkout');
            try {
                list($code, $label) = $this->reconcileOne($s, $r['status']);
                $done[] = [$id, $label, $code];
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('ucpbridge rapprochement : session ' . $id . ' : ' . $e->getMessage(), 3);
                $done[] = [$id, UcpBridgeI18n::admin('error: %message%', ['%message%' => $e->getMessage()]), 'error'];
            }
        }

        return $done;
    }

    /** @return array [code stable, libellé] */
    private function reconcileOne(array $s, $was)
    {
        $id = $s['id_session'];
        $cart = new Cart((int) $s['id_cart']);
        $this->lines->bindContext($cart);
        if ('updating' === $was) {
            // Mise à jour interrompue : le panier reflète ce qui a été écrit, la session redevient modifiable.
            $s['status'] = !empty($s['payload']['escalation_url']) ? 'requires_escalation' : 'incomplete';
            $s['status'] = $this->status->computeStatus($s, $cart);
            $this->store->save($s, ['reconciling']);
            PrestaShopLogger::addLog('ucpbridge rapprochement : session ' . $id . ', mise à jour interrompue, session rendue (' . $s['status'] . ')', 2);

            return ['update_interrupted', UcpBridgeI18n::admin('update interrupted, session released')];
        }
        $charge = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ucpbridge_payment` WHERE session_id = \'' . pSQL($id) . '\'
            AND operation = \'charge\' AND status = \'succeeded\' ORDER BY id_payment DESC');
        $refunded = $charge && Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'ucpbridge_payment` WHERE session_id = \'' . pSQL($id) . '\'
            AND operation = \'refund\' AND id_payment > ' . (int) $charge['id_payment']);
        $idOrder = (int) Order::getIdByCartId((int) $s['id_cart']);
        if ($idOrder) {
            PrestaShopLogger::addLog('ucpbridge rapprochement : session ' . $id . ' bloquée, commande ' . $idOrder
                . ' trouvée sur le panier : session terminée, aucun remboursement', 2);
            $this->completion->markCompleted($s, $idOrder, $charge ? $charge['transaction_id'] : '', $charge ? $charge['provider'] : '');

            return ['order_found', UcpBridgeI18n::admin('order %order% found, session completed', ['%order%' => $idOrder])];
        }
        if ($charge && !$refunded) {
            $providers = UcpBridgePaymentRegistry::get()->all();
            $provider = $providers[$charge['provider']] ?? null;
            $charged = ['transaction_id' => $charge['transaction_id'], 'amount' => (int) $charge['amount'], 'currency' => $charge['currency']];
            if ($provider) {
                $this->completion->orderFailedAfterCharge($s, $provider, $charged, 'processus interrompu après le débit (rapprochement)', 'rapprochement');
                $f = $this->store->load($id, 'checkout')['payload']['failure'] ?? [];

                return !empty($f['refunded']) ? ['charged_refunded', UcpBridgeI18n::admin('charge without order, refunded')]
                    : ['charged_refund_failed', UcpBridgeI18n::admin('charge without order, refund IMPOSSIBLE')];
            }
            PrestaShopLogger::addLog('ucpbridge incident (rapprochement) : session ' . $id . ', débit ' . $charge['transaction_id']
                . ' sans commande, adaptateur ' . $charge['provider'] . ' introuvable : remboursement à faire à la main', 4);
            $s['status'] = 'failed';
            $s['payload']['failure'] = ['transaction_id' => $charge['transaction_id'], 'refunded' => false, 'refund_id' => '', 'at' => gmdate('Y-m-d\TH:i:s\Z')];
            $this->store->save($s, ['reconciling']);

            return ['charged_provider_missing', UcpBridgeI18n::admin('charge without order, payment adapter not found')];
        }
        if (!empty($s['payload']['charge_started_at'])) {
            // Le processus est mort pendant l'appel au prestataire : résultat inconnu, aucune décision automatique.
            PrestaShopLogger::addLog('ucpbridge incident (rapprochement) : session ' . $id . ', débit engagé le '
                . $s['payload']['charge_started_at'] . ' sans résultat connu (' . ($s['payload']['charge_provider'] ?? '?') . ', clé d\'idempotence '
                . ($s['payload']['charge_idempotency_key'] ?? '?') . ') : vérifier chez le prestataire et rembourser si besoin', 4);
            $s['status'] = 'failed';
            $s['payload']['failure'] = ['transaction_id' => '', 'refunded' => false, 'unknown' => true, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
            $this->store->save($s, ['reconciling']);

            return ['charge_unknown', UcpBridgeI18n::admin('uncertain charge, to be checked with the payment provider')];
        }
        // Réservation sans débit engagé : la session redevient finalisable.
        $s['status'] = 'incomplete';
        $s['status'] = $this->status->computeStatus($s, $cart);
        $this->store->save($s, ['reconciling']);
        PrestaShopLogger::addLog('ucpbridge rapprochement : session ' . $id . ' bloquée sans débit, rendue (' . $s['status'] . ')', 1);

        return ['released', UcpBridgeI18n::admin('no charge, session released (%status%)', ['%status%' => $s['status']])];
    }
}
