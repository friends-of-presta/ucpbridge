<?php
/**
 * Journal de preuve des commandes UCP et demandes de rétractation en ligne.
 * Pour chaque commande : ce qui a été présenté à l'acheteur (liens légaux, version des CGV, mentions,
 * totaux), l'agent appelant (en-tête UCP-Agent), les consentements et l'éventuelle acceptation des CGV.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeProof
{
    const JSON_FIELDS = ['links', 'disclosures', 'totals', 'consent', 'terms_acceptance'];
    /** Tables conservées à la désinstallation, sauf choix explicite de les supprimer. */
    const EVIDENCE_TABLES = ['ucpbridge_proof', 'ucpbridge_withdrawal', 'ucpbridge_payment'];

    public static function installDb()
    {
        $p = _DB_PREFIX_;
        $e = _MYSQL_ENGINE_;

        return Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}ucpbridge_proof` (
                `id_proof` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order` INT UNSIGNED NOT NULL,
                `id_cart` INT UNSIGNED NOT NULL,
                `session_id` VARCHAR(40) NOT NULL,
                `agent` VARCHAR(512) NOT NULL DEFAULT '',
                `caller` VARCHAR(200) NOT NULL DEFAULT '',
                `api_ip` VARCHAR(64) NOT NULL DEFAULT '',
                `api_user_agent` VARCHAR(255) NOT NULL DEFAULT '',
                `terms_version` VARCHAR(64) NOT NULL DEFAULT '',
                `terms_acceptance` TEXT NULL,
                `links` TEXT NOT NULL,
                `disclosures` TEXT NOT NULL,
                `totals` TEXT NOT NULL,
                `consent` TEXT NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_proof`),
                UNIQUE KEY `id_order` (`id_order`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4")
            && Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}ucpbridge_withdrawal` (
                `id_order` INT UNSIGNED NOT NULL,
                `email` VARCHAR(255) NOT NULL,
                `ip` VARCHAR(64) NOT NULL DEFAULT '',
                `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_order`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4")
            // Journal des opérations de paiement (débits, remboursements) : base des incidents « débit sans commande ».
            && Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}ucpbridge_payment` (
                `id_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `session_id` VARCHAR(40) NOT NULL,
                `provider` VARCHAR(64) NOT NULL,
                `operation` VARCHAR(16) NOT NULL,
                `status` VARCHAR(32) NOT NULL,
                `transaction_id` VARCHAR(255) NOT NULL DEFAULT '',
                `amount` INT NOT NULL DEFAULT 0,
                `currency` CHAR(3) NOT NULL DEFAULT '',
                `message` VARCHAR(255) NOT NULL DEFAULT '',
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_payment`),
                KEY `session_id` (`session_id`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4");
    }

    public static function payment($sessionId, $provider, $operation, $status, $transactionId, $amount, $currency, $message = '')
    {
        return Db::getInstance()->insert('ucpbridge_payment', [
            'session_id' => pSQL($sessionId),
            'provider' => pSQL(substr((string) $provider, 0, 64)),
            'operation' => pSQL($operation),
            'status' => pSQL((string) $status),
            'transaction_id' => pSQL(substr((string) $transactionId, 0, 255)),
            'amount' => (int) $amount,
            'currency' => pSQL(substr((string) $currency, 0, 3)),
            'message' => pSQL(substr((string) $message, 0, 255)),
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Désinstallation : le journal de preuve, les rétractations et le journal des paiements sont conservés (le marchand
     * peut devoir les produire en cas de litige, et une réinstallation les retrouve). Ils ne sont supprimés que sur
     * choix explicite ($drop, option « supprimer à la désinstallation » de la configuration).
     */
    public static function uninstallDb($drop = false)
    {
        if (!$drop) {
            return;
        }
        foreach (self::EVIDENCE_TABLES as $t) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $t . '`');
        }
    }

    private static function json($v)
    {
        return pSQL(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    }

    public static function record(array $p)
    {
        return Db::getInstance()->insert('ucpbridge_proof', [
            'id_order' => (int) $p['id_order'],
            'id_cart' => (int) $p['id_cart'],
            'session_id' => pSQL($p['session_id']),
            'agent' => pSQL(substr((string) $p['agent'], 0, 512)),
            'caller' => pSQL(substr((string) ($p['caller'] ?? ''), 0, 200)),
            'api_ip' => pSQL(substr((string) ($p['api_ip'] ?? ''), 0, 64)),
            'api_user_agent' => pSQL(substr((string) ($p['api_user_agent'] ?? ''), 0, 255)),
            'terms_version' => pSQL($p['terms_version']),
            'terms_acceptance' => null === $p['terms_acceptance'] ? null : self::json($p['terms_acceptance']),
            'links' => self::json($p['links']),
            'disclosures' => self::json($p['disclosures']),
            'totals' => self::json($p['totals']),
            'consent' => self::json($p['consent']),
            'date_add' => date('Y-m-d H:i:s'),
        ], true, false, Db::INSERT_IGNORE);
    }

    public static function get($idOrder)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ucpbridge_proof` WHERE id_order = ' . (int) $idOrder);
        if (!$row) {
            return null;
        }
        foreach (self::JSON_FIELDS as $f) {
            $row[$f] = null === $row[$f] ? null : json_decode((string) $row[$f], true);
        }

        return $row;
    }

    public static function withdrawal($idOrder)
    {
        return Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ucpbridge_withdrawal` WHERE id_order = ' . (int) $idOrder) ?: null;
    }

    /** Enregistre la demande (une seule par commande). Retourne true si elle vient d'être créée. */
    public static function recordWithdrawal($idOrder, $email, $ip)
    {
        Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'ucpbridge_withdrawal` (id_order, email, ip, email_sent, date_add)
            VALUES (' . (int) $idOrder . ', \'' . pSQL($email) . '\', \'' . pSQL(substr((string) $ip, 0, 64)) . '\', 0, \'' . pSQL(date('Y-m-d H:i:s')) . '\')');

        return 1 === (int) Db::getInstance()->Affected_Rows();
    }

    public static function markWithdrawalMailed($idOrder)
    {
        Db::getInstance()->update('ucpbridge_withdrawal', ['email_sent' => 1], 'id_order = ' . (int) $idOrder);
    }
}
