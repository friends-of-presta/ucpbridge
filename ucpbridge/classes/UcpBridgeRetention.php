<?php
/**
 * Durées de conservation des données du module (RGPD, article 5.1.e : limitation de la conservation), par catégorie,
 * en jours. 0 = jamais purgé. Logique pure : la purge elle-même est dans UcpBridgePurge.
 *
 * Valeurs par défaut :
 * - sessions de caisse et paniers terminés ou expirés : 30 jours après la dernière activité (e-mail, nom, adresse,
 *   téléphone de l'acheteur). Une session terminée par une commande n'est pas supprimée (l'agent relit la commande par
 *   elle) : ses données personnelles sont retirées, la commande PrestaShop garde les siennes ;
 * - clients invités créés par le module et sans aucune commande : 30 jours après leur création ;
 * - codes et jetons OAuth : 30 jours après leur expiration (ou leur révocation) ;
 * - journal des paiements : 3650 jours (10 ans, pièces comptables, article L123-22 du Code de commerce) ;
 * - journal de preuve et rétractations : 3650 jours (10 ans, conservation des contrats conclus par voie électronique à
 *   partir de 120 euros, articles L213-1 et D213-2 du Code de la consommation).
 * Ces deux dernières durées ne peuvent pas descendre sous 3650 jours (LEGAL_MIN_DAYS) ; 0 (jamais) reste permis.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeRetention
{
    /** Durée maximale acceptée (100 ans). */
    const MAX_DAYS = 36500;

    /** Catégorie => [clé de configuration, durée par défaut en jours] (libellé : label()). */
    const CATEGORIES = [
        'sessions' => ['UCPBRIDGE_RETENTION_SESSIONS', 30],
        'guests' => ['UCPBRIDGE_RETENTION_GUESTS', 30],
        'oauth' => ['UCPBRIDGE_RETENTION_OAUTH', 30],
        'payments' => ['UCPBRIDGE_RETENTION_PAYMENTS', 3650],
        'proofs' => ['UCPBRIDGE_RETENTION_PROOFS', 3650],
    ];

    /**
     * Durées légales : plancher par catégorie (jours). Une durée plus courte est refusée à l'enregistrement et bloque la
     * purge ; 0 (jamais purgé) reste permis. 10 ans : pièces comptables (article L123-22 du Code de commerce) et
     * contrats conclus par voie électronique (articles L213-1 et D213-2 du Code de la consommation).
     */
    const LEGAL_MIN_DAYS = ['payments' => 3650, 'proofs' => 3650];

    /** Données personnelles d'une session, retirées d'une session terminée par une commande. */
    const PERSONAL_KEYS = ['buyer', 'consent', 'destination', 'terms_acceptance', 'context'];

    /** Lignes du compte rendu, dans l'ordre d'affichage (codes stables ; libellés : reportLabel()). */
    const REPORT = ['sessions_deleted', 'sessions_minimized', 'carts', 'addresses', 'guests', 'oauth_codes', 'oauth_tokens', 'payments',
        'proofs', 'withdrawals', 'idempotency'];

    /** Libellé traduit d'une catégorie de données. */
    public static function label($cat)
    {
        switch ($cat) {
            case 'sessions':
                return UcpBridgeI18n::admin('Checkout sessions and carts completed or expired');
            case 'guests':
                return UcpBridgeI18n::admin('Guest customers created by the module, without an order');
            case 'oauth':
                return UcpBridgeI18n::admin('Expired or revoked OAuth codes and tokens');
            case 'payments':
                return UcpBridgeI18n::admin('Payment log (charges, refunds)');
            case 'proofs':
                return UcpBridgeI18n::admin('Evidence log and withdrawal requests');
        }

        return (string) $cat;
    }

    /** Libellé traduit d'une ligne du compte rendu, après purge ou en simulation. */
    public static function reportLabel($key, $dryRun)
    {
        $labels = [
            'sessions_deleted' => [UcpBridgeI18n::admin('UCP sessions and carts deleted'), UcpBridgeI18n::admin('UCP sessions and carts to delete')],
            'sessions_minimized' => [UcpBridgeI18n::admin('Sessions completed by an order, personal data removed'),
                UcpBridgeI18n::admin('Sessions completed by an order, personal data to remove')],
            'carts' => [UcpBridgeI18n::admin('PrestaShop carts without an order deleted'), UcpBridgeI18n::admin('PrestaShop carts without an order to delete')],
            'addresses' => [UcpBridgeI18n::admin('Addresses without a customer deleted'), UcpBridgeI18n::admin('Addresses without a customer to delete')],
            'guests' => [UcpBridgeI18n::admin('Guest customers without an order deleted'), UcpBridgeI18n::admin('Guest customers without an order to delete')],
            'oauth_codes' => [UcpBridgeI18n::admin('OAuth codes deleted'), UcpBridgeI18n::admin('OAuth codes to delete')],
            'oauth_tokens' => [UcpBridgeI18n::admin('OAuth tokens deleted'), UcpBridgeI18n::admin('OAuth tokens to delete')],
            'payments' => [UcpBridgeI18n::admin('Payment log entries deleted'), UcpBridgeI18n::admin('Payment log entries to delete')],
            'proofs' => [UcpBridgeI18n::admin('Order evidence records deleted'), UcpBridgeI18n::admin('Order evidence records to delete')],
            'withdrawals' => [UcpBridgeI18n::admin('Withdrawal requests deleted'), UcpBridgeI18n::admin('Withdrawal requests to delete')],
            'idempotency' => [UcpBridgeI18n::admin('Idempotency keys older than 24 h deleted'), UcpBridgeI18n::admin('Idempotency keys older than 24 h to delete')],
        ];

        return isset($labels[$key]) ? $labels[$key][$dryRun ? 1 : 0] : (string) $key;
    }

    /** Configuration par défaut : clé => durée. */
    public static function defaults()
    {
        $d = [];
        foreach (self::CATEGORIES as $c) {
            $d[$c[0]] = $c[1];
        }

        return $d;
    }

    /** Durée saisie : entier de 0 à MAX_DAYS (chiffres seulement), sinon null. */
    public static function normalizeDays($v)
    {
        $v = trim((string) $v);
        if (1 !== preg_match('/^\d{1,6}\z/', $v) || (int) $v > self::MAX_DAYS) {
            return null;
        }

        return (int) $v;
    }

    /** Durée trop courte pour une catégorie à durée légale (0 = jamais purgé : toujours permis). */
    public static function tooShort($cat, $days)
    {
        $days = (int) $days;

        return $days > 0 && isset(self::LEGAL_MIN_DAYS[$cat]) && $days < self::LEGAL_MIN_DAYS[$cat];
    }

    /** Catégories dont la durée est sous le plancher légal : catégorie => durée. */
    public static function tooShortAll(array $durations)
    {
        $bad = [];
        foreach ($durations as $cat => $days) {
            if (self::tooShort($cat, $days)) {
                $bad[$cat] = (int) $days;
            }
        }

        return $bad;
    }

    /** Message de refus d'une durée trop courte, à l'enregistrement. */
    public static function tooShortMessage($cat)
    {
        return UcpBridgeI18n::admin('Retention period too short (%category%): the law requires keeping these records for 10 years. Enter at least %min% days, or 0 to never purge. No period changed.',
            ['%category%' => self::label($cat), '%min%' => self::LEGAL_MIN_DAYS[$cat]]);
    }

    /** Message de refus de la purge (durées trop courtes en configuration), en ligne de commande et au back-office. */
    public static function purgeRefusedMessage(array $bad)
    {
        $list = [];
        foreach ($bad as $cat => $days) {
            $list[] = UcpBridgeI18n::admin('%category%: %days% days, legal minimum %min% days', ['%category%' => self::label($cat),
                '%days%' => (int) $days, '%min%' => self::LEGAL_MIN_DAYS[$cat]]);
        }

        return UcpBridgeI18n::admin('Purge refused, nothing was deleted: retention period too short (%list%; or 0 to never purge). Fix the periods in the module configuration.',
            ['%list%' => implode('; ', $list)]);
    }

    /** Date limite (Y-m-d H:i:s) : ce qui est plus ancien est purgé. null si la durée vaut 0 (jamais purgé). */
    public static function cutoff($days, $now)
    {
        $days = (int) $days;
        if ($days <= 0) {
            return null;
        }

        return date('Y-m-d H:i:s', (int) $now - 86400 * $days);
    }

    /** Session terminée par une commande, sans les données personnelles de l'acheteur (identifiants conservés). */
    public static function minimizePayload(array $payload, $now)
    {
        foreach (self::PERSONAL_KEYS as $k) {
            unset($payload[$k]);
        }
        $payload['minimized_at'] = gmdate('Y-m-d\TH:i:s\Z', (int) $now);

        return $payload;
    }

    /** Compte rendu lisible : code stable => « libellé : nombre », une ligne par catégorie, dans l'ordre de REPORT. */
    public static function summary(array $counts, $dryRun)
    {
        $lines = [];
        foreach (self::REPORT as $k) {
            $lines[$k] = self::reportLabel($k, $dryRun) . ' : ' . (int) ($counts[$k] ?? 0);
        }

        return $lines;
    }
}
