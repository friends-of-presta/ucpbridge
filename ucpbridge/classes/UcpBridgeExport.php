<?php
/**
 * Export CSV du journal de preuve et des demandes de rétractation (configuration du module), pour les produire en cas
 * de litige ou les archiver avant une désinstallation. Une ligne par commande : la preuve et, s'il y en a une, la
 * rétractation ; puis les rétractations sans preuve. Séparateur « ; », UTF-8 avec BOM et fins de ligne CRLF, pour
 * une ouverture directe dans un tableur français. Les champs JSON (liens, mentions, totaux, consentements, acceptation
 * des CGV) sont exportés tels qu'ils sont enregistrés.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeExport
{
    const SEPARATOR = ';';
    const BOM = "\xEF\xBB\xBF";

    /** En-tête du fichier => colonne de la requête. */
    const COLUMNS = [
        'id_commande' => 'id_order',
        'reference_commande' => 'reference',
        'date_preuve' => 'date_add',
        'session' => 'session_id',
        'agent_ucp' => 'agent',
        'appelant' => 'caller',
        'ip_appel_api' => 'api_ip',
        'user_agent_appel_api' => 'api_user_agent',
        'version_cgv' => 'terms_version',
        'acceptation_cgv' => 'terms_acceptance',
        'liens_legaux' => 'links',
        'mentions' => 'disclosures',
        'totaux' => 'totals',
        'consentements' => 'consent',
        'retractation_date' => 'w_date_add',
        'retractation_email' => 'w_email',
        'retractation_ip' => 'w_ip',
        'retractation_accuse_envoye' => 'w_email_sent',
    ];

    /* ------------------------------ Logique pure (testée sans PrestaShop) ------------------------------ */

    /**
     * Cellule sûre pour un tableur : une valeur qui commence par = + - @, une tabulation ou un retour chariot serait
     * interprétée comme une formule (l'en-tête UCP-Agent, par exemple, vient de l'agent). Elle est préfixée d'une
     * apostrophe.
     */
    public static function cell($v)
    {
        $v = null === $v ? '' : (string) $v;
        if ('' !== $v && false !== strpos("=+-@\t\r", $v[0])) {
            $v = "'" . $v;
        }

        return $v;
    }

    /** Ligne CSV (RFC 4180) : guillemets doublés, champ entre guillemets s'il contient séparateur, guillemet ou saut. */
    public static function line(array $cells)
    {
        $out = [];
        foreach ($cells as $c) {
            $c = self::cell($c);
            $out[] = preg_match('/[;"\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
        }

        return implode(self::SEPARATOR, $out) . "\r\n";
    }

    /** Cellules d'un enregistrement, dans l'ordre de l'en-tête. */
    public static function cells(array $row)
    {
        $cells = [];
        foreach (self::COLUMNS as $col) {
            $cells[] = $row[$col] ?? '';
        }

        return $cells;
    }

    /* ------------------------------ Base de la boutique ------------------------------ */

    /** Preuves (avec la rétractation éventuelle), puis rétractations sans preuve, par commande. */
    public static function rows()
    {
        $p = _DB_PREFIX_;

        return Db::getInstance()->executeS("SELECT p.id_order, o.reference, p.date_add, p.session_id, p.agent, p.caller, p.api_ip,
                p.api_user_agent, p.terms_version, p.terms_acceptance, p.links, p.disclosures, p.totals, p.consent,
                w.date_add AS w_date_add, w.email AS w_email, w.ip AS w_ip, w.email_sent AS w_email_sent
            FROM `{$p}ucpbridge_proof` p
            LEFT JOIN `{$p}orders` o ON o.id_order = p.id_order
            LEFT JOIN `{$p}ucpbridge_withdrawal` w ON w.id_order = p.id_order
            UNION ALL
            SELECT w.id_order, o.reference, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
                w.date_add, w.email, w.ip, w.email_sent
            FROM `{$p}ucpbridge_withdrawal` w
            LEFT JOIN `{$p}ucpbridge_proof` p ON p.id_order = w.id_order
            LEFT JOIN `{$p}orders` o ON o.id_order = w.id_order
            WHERE p.id_order IS NULL
            ORDER BY id_order", true, false) ?: [];
    }

    /** Écrit le fichier complet dans le flux (php://output pour le téléchargement). Retourne le nombre de lignes. */
    public static function write($handle)
    {
        fwrite($handle, self::BOM . self::line(array_keys(self::COLUMNS)));
        $n = 0;
        foreach (self::rows() as $r) {
            fwrite($handle, self::line(self::cells($r)));
            ++$n;
        }

        return $n;
    }

    public static function filename($now)
    {
        return 'ucpbridge-preuves-' . date('Ymd-His', (int) $now) . '.csv';
    }
}
