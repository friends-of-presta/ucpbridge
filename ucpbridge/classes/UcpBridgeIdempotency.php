<?php
/**
 * Idempotency-Key UCP : la clé est conservée 24 h avec la réponse. Même clé et même requête :
 * on rejoue la réponse enregistrée. Même clé et requête différente : 409. Requête encore en cours : 409.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeIdempotency
{
    const TTL = 86400;

    private static function table()
    {
        return '`' . _DB_PREFIX_ . 'ucpbridge_idempotency`';
    }

    /** Empreinte stable : méthode, chemin et corps JSON canonique (clés triées). */
    public static function fingerprint($method, $path, $body)
    {
        $canon = function ($v) use (&$canon) {
            if (is_array($v)) {
                if (array_keys($v) !== range(0, count($v) - 1)) {
                    ksort($v);
                }

                return array_map($canon, $v);
            }

            return $v;
        };

        return hash('sha256', strtoupper($method) . ' ' . $path . ' ' . json_encode($canon($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Clé stockée : empreinte de l'appelant et de la clé fournie (longueur fixe, aucune collision entre appelants). */
    public static function scopedKey($caller, $key)
    {
        return hash('sha256', (string) $caller . '|' . (string) $key);
    }

    /**
     * Clé d'idempotence transmise au prestataire pour un débit : session, numéro de tentative, puis empreinte de
     * l'instrument, du montant (centimes) et de la devise. Un panier modifié (autre montant) ou un nouvel essai après
     * un refus définitif (tentative suivante) produit une autre clé : le prestataire ne rejoue jamais un refus mis en
     * cache ni ne rejette la requête pour paramètres différents. Le même essai rejoué (même tentative, même montant)
     * garde la même clé : aucun double débit.
     */
    public static function chargeKey($sessionId, array $instrument, $cents, $currency, $attempt = 0)
    {
        $print = hash('sha256', json_encode([$instrument, (int) $cents, strtoupper((string) $currency)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return (string) $sessionId . '-' . max(0, (int) $attempt) . '-' . substr($print, 0, 16);
    }

    /**
     * Réserve la clé. Retourne null si la requête doit être exécutée,
     * sinon [code HTTP, corps] à renvoyer tel quel (rejeu ou conflit).
     */
    public static function begin($key, $hash)
    {
        $db = Db::getInstance();
        $db->execute('DELETE FROM ' . self::table() . ' WHERE date_add < \'' . pSQL(date('Y-m-d H:i:s', time() - self::TTL)) . '\'');
        $inserted = $db->execute('INSERT IGNORE INTO ' . self::table() . ' (idem_key, request_hash, http_code, response, date_add)
            VALUES (\'' . pSQL($key) . '\', \'' . pSQL($hash) . '\', 0, NULL, \'' . pSQL(date('Y-m-d H:i:s')) . '\')');
        if ($inserted && 1 === (int) $db->Affected_Rows()) {
            return null;
        }
        return self::decide($db->getRow('SELECT request_hash, http_code, response FROM ' . self::table() . ' WHERE idem_key = \'' . pSQL($key) . '\''), $hash);
    }

    /**
     * Décision à partir de la ligne existante : null = exécuter, sinon [code HTTP, corps, rejeu ?].
     *
     * @param array|false|null $row
     */
    public static function decide($row, $hash)
    {
        if (!$row) {
            return null;
        }
        if (!hash_equals((string) $row['request_hash'], (string) $hash)) {
            return [409, ['code' => 'idempotency_conflict', 'content' => UcpBridgeI18n::api('Idempotency-Key already used with a different request')]];
        }
        if (0 === (int) $row['http_code']) {
            return [409, ['code' => 'idempotency_in_progress', 'content' => UcpBridgeI18n::api('A request with this Idempotency-Key is still in progress')]];
        }

        return [(int) $row['http_code'], json_decode((string) $row['response'], true), true];
    }

    public static function finish($key, $code, $body)
    {
        if ($code >= 500) {
            // Exception imprévue : on libère la clé pour permettre une nouvelle tentative. Aucun effet de bord payant ne
            // peut précéder ce cas : un échec après débit est rattrapé par la caisse (état terminal, réponse 200 enregistrée).
            Db::getInstance()->execute('DELETE FROM ' . self::table() . ' WHERE idem_key = \'' . pSQL($key) . '\'');

            return;
        }
        Db::getInstance()->execute('UPDATE ' . self::table() . ' SET http_code = ' . (int) $code
            . ', response = \'' . pSQL(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) . '\'
            WHERE idem_key = \'' . pSQL($key) . '\'');
    }
}
