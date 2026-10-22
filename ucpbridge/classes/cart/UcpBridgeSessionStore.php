<?php
/**
 * Stockage des sessions UCP (paniers et sessions de caisse, table ucpbridge_session) : lecture, écriture gardée par
 * statut, réservation atomique. Porte aussi les statuts internes et leur traduction en statuts de la spécification.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeSessionStore
{
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

    /** @var UcpBridgeCaller propriétaire inscrit à la création d'une session */
    private $caller;

    public function __construct(UcpBridgeCaller $caller)
    {
        $this->caller = $caller;
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
    public function reserve($id, array $from, $to, $before = null)
    {
        $db = Db::getInstance();
        $db->update('ucpbridge_session', ['status' => pSQL($to), 'date_upd' => date('Y-m-d H:i:s')],
            'id_session = \'' . pSQL($id) . '\' AND status IN (\'' . implode('\',\'', array_map('pSQL', $from)) . '\')'
            . (null !== $before ? ' AND date_upd < \'' . pSQL($before) . '\'' : ''));

        return 1 === (int) $db->Affected_Rows();
    }

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
    public function save(array $s, ?array $from = null)
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
        $data['owner'] = pSQL($this->caller->owner);
        $data['date_add'] = $now;

        return (bool) $db->insert('ucpbridge_session', $data);
    }
}
