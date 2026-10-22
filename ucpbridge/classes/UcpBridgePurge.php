<?php
/**
 * Purge RGPD selon les durées de conservation (UcpBridgeRetention), lancée par bin/purge.php (tâche planifiée) ou par
 * le bouton de la configuration. Renvoie un compte rendu chiffré par catégorie, aussi consigné au journal PrestaShop.
 *
 * Garanties :
 * - aucune commande n'est touchée (ps_orders, détails, paiements, adresses de livraison des commandes) ;
 * - une session terminée par une commande n'est jamais supprimée : seules les données personnelles de son JSON sont
 *   retirées (l'agent relit toujours la commande) ;
 * - une session en cours de finalisation ou de rapprochement (complete_in_progress, updating, reconciling) n'est
 *   jamais touchée ; une session « failed » (incident de paiement : débit non remboursé, débit incertain) suit la durée
 *   du journal des paiements et n'est supprimée par rien d'autre ;
 * - seuls les clients invités enregistrés comme créés par le module (ps_ucpbridge_customer) et sans AUCUNE commande
 *   sont supprimés (Customer::delete : adresses inutilisées, paniers sans commande) ; un invité qui a commandé, ou
 *   devenu client avec compte, ne l'est jamais, pas plus qu'un invité qui a une session « failed » (l'acheteur à
 *   rembourser reste joignable aussi longtemps que le journal des paiements) ;
 * - durées légales (journal des paiements, preuves et rétractations) jamais sous leur plancher
 *   (UcpBridgeRetention::LEGAL_MIN_DAYS) : sinon la purge refuse de tourner ;
 * - le compte rendu compte tout ce qui part, y compris les paniers et adresses supprimés avec un invité, et la
 *   simulation donne les mêmes nombres que la purge ;
 * - les sessions sont lues par lots (BATCH), sans relire celles déjà minimisées.
 * - les paniers PrestaShop supprimés sont ceux d'une session purgée, sans commande, d'un visiteur sans compte (sans
 *   client ou invité créé par le module) : le panier abandonné d'un client avec compte reste à la boutique.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgePurge
{
    /** Sessions lues par lot. */
    const BATCH = 500;

    /** @var bool simulation : compter sans rien supprimer */
    private $dryRun;
    /** @var int */
    private $now;
    /** @var int */
    private $batch;
    /** @var array */
    private $counts;
    /** @var array sessions et paniers déjà comptés (simulation : une session n'est comptée qu'une fois) */
    private $seen;

    public function __construct($dryRun = false, $now = null, $batch = self::BATCH)
    {
        $this->dryRun = (bool) $dryRun;
        $this->now = null === $now ? time() : (int) $now;
        $this->batch = max(1, (int) $batch);
    }

    /** Durées sous leur plancher légal dans la configuration : catégorie => durée (vide si tout est conforme). */
    public static function refusedDurations()
    {
        return UcpBridgeRetention::tooShortAll(self::durations());
    }

    /** Durées configurées par catégorie (jours, 0 = jamais). */
    public static function durations()
    {
        $d = [];
        foreach (UcpBridgeRetention::CATEGORIES as $cat => $c) {
            $v = Configuration::get($c[0]);
            $d[$cat] = false === $v || null === $v || '' === $v ? (int) $c[1] : max(0, (int) $v);
        }

        return $d;
    }

    /** Enregistre un client invité créé par le module (lu par la purge). */
    public static function recordGuest($idCustomer)
    {
        return Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'ucpbridge_customer` (id_customer, date_add)
            VALUES (' . (int) $idCustomer . ', \'' . pSQL(date('Y-m-d H:i:s')) . '\')');
    }

    /**
     * Reprise des clients invités créés avant la 0.1.3 (mise à jour) : ceux qu'une session du module a créés
     * (payload.id_customer) et qui sont toujours invités. Date : celle de leur création. Rejouable.
     */
    public static function backfillGuests()
    {
        $p = _DB_PREFIX_;
        $ok = true;
        $rows = Db::getInstance()->executeS("SELECT payload FROM `{$p}ucpbridge_session` WHERE payload LIKE '%\"id_customer\"%'") ?: [];
        foreach ($rows as $r) {
            $id = (int) ((json_decode((string) $r['payload'], true) ?: [])['id_customer'] ?? 0);
            if ($id) {
                $ok = Db::getInstance()->execute("INSERT IGNORE INTO `{$p}ucpbridge_customer` (id_customer, date_add)
                    SELECT id_customer, date_add FROM `{$p}customer` WHERE id_customer = {$id} AND is_guest = 1") && $ok;
            }
        }

        return $ok;
    }

    /** @return array compte par ligne du compte rendu (clés de UcpBridgeRetention::REPORT) */
    public function run()
    {
        $d = self::durations();
        if ($bad = UcpBridgeRetention::tooShortAll($d)) {
            // Durée légale trop courte (écrite hors du back-office) : rien n'est supprimé.
            throw new RuntimeException(UcpBridgeRetention::purgeRefusedMessage($bad));
        }
        $this->counts = array_fill_keys(array_keys(UcpBridgeRetention::REPORT), 0);
        $this->seen = ['sessions' => [], 'carts' => []];
        $cut = function ($cat) use ($d) {
            return UcpBridgeRetention::cutoff($d[$cat], $this->now);
        };
        if ($c = $cut('sessions')) {
            $this->purgeSessions($c);
        }
        if ($c = $cut('payments')) {
            // Sessions en échec (incident de paiement) : gardées aussi longtemps que le journal des paiements.
            $this->purgeSessions($c, true);
            $this->deleteWhere('payments', 'ucpbridge_payment', 'date_add < \'' . pSQL($c) . '\'');
        }
        if ($c = $cut('guests')) {
            $this->purgeGuests($c);
        }
        if ($c = $cut('oauth')) {
            $this->deleteWhere('oauth_codes', 'ucpbridge_oauth_code', 'expires_at < \'' . pSQL($c) . '\'');
            $this->deleteWhere('oauth_tokens', 'ucpbridge_oauth_token', 'expires_at < \'' . pSQL($c) . '\' OR (revoked = 1 AND date_add < \'' . pSQL($c) . '\')');
        }
        if ($c = $cut('proofs')) {
            $this->deleteWhere('proofs', 'ucpbridge_proof', 'date_add < \'' . pSQL($c) . '\'');
            $this->deleteWhere('withdrawals', 'ucpbridge_withdrawal', 'date_add < \'' . pSQL($c) . '\'');
        }
        // Clés d'idempotence : 24 h (UcpBridgeIdempotency::TTL), d'ordinaire purgées au fil des requêtes.
        $this->deleteWhere('idempotency', 'ucpbridge_idempotency', 'date_add < \'' . pSQL(date('Y-m-d H:i:s', $this->now - UcpBridgeIdempotency::TTL)) . '\'');

        if (!$this->dryRun) {
            PrestaShopLogger::addLog('ucpbridge purge RGPD : ' . implode(' ; ', UcpBridgeRetention::summary($this->counts, false)), 1);
        }

        return $this->counts;
    }

    private function deleteWhere($key, $table, $where)
    {
        $t = '`' . _DB_PREFIX_ . $table . '`';
        if ($this->dryRun) {
            $this->counts[$key] += (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . $t . ' WHERE ' . $where, false);

            return;
        }
        Db::getInstance()->execute('DELETE FROM ' . $t . ' WHERE ' . $where);
        $this->counts[$key] += (int) Db::getInstance()->Affected_Rows();
    }

    /**
     * Sessions terminées ou expirées avant la date limite. $failed : seulement les sessions en échec (incident de
     * paiement), sinon toutes les autres hors réservations en cours.
     */
    private function purgeSessions($cutoff, $failed = false)
    {
        $after = '';
        do {
            $rows = $this->sessionBatch($cutoff, $failed, $after, $this->batch);
            foreach ($rows as $r) {
                $after = (string) $r['id_session'];
                $payload = json_decode((string) $r['payload'], true) ?: [];
                if ('completed' === $r['status'] || (int) $r['id_order'] > 0) {
                    if (empty($payload['minimized_at'])) {
                        $this->minimize($r['id_session'], $payload);
                    }
                    continue;
                }
                $this->deleteSession($r['id_session'], (int) $r['id_cart'], $payload);
            }
        } while (count($rows) >= $this->batch);
    }

    /**
     * Lot de sessions à traiter, par identifiant croissant après $after (pagination stable, en simulation aussi).
     * Les sessions déjà minimisées sont écartées en SQL : elles ne sont plus relues à chaque purge.
     */
    public function sessionBatch($cutoff, $failed, $after, $limit)
    {
        $p = _DB_PREFIX_;
        $c = pSQL($cutoff);
        $busy = "'" . implode("','", UcpBridgeCart::BUSY) . "'";
        $where = $failed
            ? "status = 'failed' AND date_upd < '{$c}'"
            : "status NOT IN ({$busy}, 'failed') AND date_upd < '{$c}' AND (status IN ('completed', 'canceled', 'handed_off') OR expires_at < '{$c}')";
        // Clé JSON exacte (guillemets non échappés : une valeur saisie par l'acheteur ne peut pas l'imiter).
        $where .= ' AND payload NOT LIKE \'%"minimized\\_at":%\'';
        if ('' !== (string) $after) {
            $where .= " AND id_session > '" . pSQL($after) . "'";
        }

        return Db::getInstance()->executeS("SELECT id_session, status, id_cart, id_order, payload FROM `{$p}ucpbridge_session`
            WHERE {$where} ORDER BY id_session LIMIT " . max(1, (int) $limit), true, false) ?: [];
    }

    private function minimize($idSession, array $payload)
    {
        ++$this->counts['sessions_minimized'];
        if ($this->dryRun) {
            return;
        }
        Db::getInstance()->update('ucpbridge_session', [
            'payload' => pSQL(json_encode(UcpBridgeRetention::minimizePayload($payload, $this->now), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true),
        ], 'id_session = \'' . pSQL($idSession) . '\' AND status = \'completed\'');
    }

    /** Session sans commande : la ligne, son panier PrestaShop (visiteur sans compte) et son adresse sans client. */
    private function deleteSession($idSession, $idCart, array $payload)
    {
        $p = _DB_PREFIX_;
        ++$this->counts['sessions_deleted'];
        $this->seen['sessions'][(string) $idSession] = true;
        if ($idCart && !Order::getIdByCartId($idCart)) {
            $owner = (int) Db::getInstance()->getValue("SELECT id_customer FROM `{$p}cart` WHERE id_cart = {$idCart}", false);
            $guest = $owner && Db::getInstance()->getValue("SELECT 1 FROM `{$p}ucpbridge_customer` m JOIN `{$p}customer` cu USING (id_customer)
                WHERE m.id_customer = {$owner} AND cu.is_guest = 1", false);
            if (Db::getInstance()->getValue("SELECT 1 FROM `{$p}cart` WHERE id_cart = {$idCart}", false) && (!$owner || $guest)) {
                ++$this->counts['carts'];
                $this->seen['carts'][$idCart] = true;
                if (!$this->dryRun) {
                    (new Cart($idCart))->delete();
                }
            }
        }
        $idAddress = (int) ($payload['id_address'] ?? 0);
        if ($idAddress && '0' === (string) Db::getInstance()->getValue("SELECT id_customer FROM `{$p}address` WHERE id_address = {$idAddress} AND deleted = 0", false)) {
            ++$this->counts['addresses'];
            if (!$this->dryRun) {
                (new Address($idAddress))->delete(); // suppression logique si l'adresse sert à une commande
            }
        }
        if (!$this->dryRun) {
            Db::getInstance()->delete('ucpbridge_session', 'id_session = \'' . pSQL($idSession) . '\'');
        }
    }

    /**
     * Clients invités créés par le module avant la date limite, sans aucune commande, sans session en cours et sans
     * session « failed » (incident de paiement : l'acheteur est peut-être à rembourser ; il redevient éligible quand la
     * session part avec le journal des paiements).
     */
    private function purgeGuests($cutoff)
    {
        $p = _DB_PREFIX_;
        $c = pSQL($cutoff);
        $now = pSQL(date('Y-m-d H:i:s', $this->now));
        $busy = "'" . implode("','", UcpBridgeCart::BUSY) . "'";
        // Mappage orphelin (client supprimé par ailleurs) : retiré sans compter.
        if (!$this->dryRun) {
            Db::getInstance()->execute("DELETE m FROM `{$p}ucpbridge_customer` m LEFT JOIN `{$p}customer` cu USING (id_customer) WHERE cu.id_customer IS NULL");
        }
        $ids = Db::getInstance()->executeS("SELECT m.id_customer FROM `{$p}ucpbridge_customer` m
            JOIN `{$p}customer` cu ON cu.id_customer = m.id_customer AND cu.is_guest = 1
            WHERE m.date_add < '{$c}'
            AND NOT EXISTS (SELECT 1 FROM `{$p}orders` o WHERE o.id_customer = m.id_customer)
            AND NOT EXISTS (SELECT 1 FROM `{$p}ucpbridge_session` s JOIN `{$p}cart` ca ON ca.id_cart = s.id_cart
                WHERE ca.id_customer = m.id_customer AND (s.status IN ({$busy}, 'failed') OR (s.status NOT IN ('completed', 'canceled', 'handed_off')
                AND s.expires_at > '{$now}')))", true, false) ?: [];
        foreach ($ids as $row) {
            $id = (int) $row['id_customer'];
            $customer = new Customer($id);
            // Vérification AVANT toute suppression : jamais un client qui a commandé, ni devenu client avec compte.
            if (!Validate::isLoadedObject($customer) || !$customer->is_guest
                || Db::getInstance()->getValue("SELECT 1 FROM `{$p}orders` WHERE id_customer = {$id}", false)) {
                continue;
            }
            // Sessions restantes de ce client (sans commande, terminées ou expirées), jamais une session « failed ».
            $sessions = Db::getInstance()->executeS("SELECT s.id_session FROM `{$p}ucpbridge_session` s JOIN `{$p}cart` ca ON ca.id_cart = s.id_cart
                WHERE ca.id_customer = {$id} AND s.status NOT IN ({$busy}, 'failed')", true, false) ?: [];
            // Paniers et adresses que Customer::delete() supprime avec lui (comptés aussi en simulation).
            $carts = Db::getInstance()->executeS("SELECT ca.id_cart FROM `{$p}cart` ca WHERE ca.id_customer = {$id}
                AND NOT EXISTS (SELECT 1 FROM `{$p}orders` o WHERE o.id_cart = ca.id_cart)", true, false) ?: [];
            $addresses = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}address` WHERE id_customer = {$id} AND deleted = 0", false);
            foreach ($sessions as $s) {
                if (!isset($this->seen['sessions'][(string) $s['id_session']])) {
                    ++$this->counts['sessions_deleted'];
                }
            }
            foreach ($carts as $ca) {
                if (!isset($this->seen['carts'][(int) $ca['id_cart']])) {
                    ++$this->counts['carts'];
                }
            }
            $this->counts['addresses'] += $addresses;
            ++$this->counts['guests'];
            if ($this->dryRun) {
                continue;
            }
            foreach ($sessions as $s) {
                Db::getInstance()->delete('ucpbridge_session', 'id_session = \'' . pSQL($s['id_session']) . '\' AND status NOT IN (' . $busy . ', \'failed\')');
            }
            $customer->delete();
            Db::getInstance()->delete('ucpbridge_customer', 'id_customer = ' . $id);
        }
    }
}
