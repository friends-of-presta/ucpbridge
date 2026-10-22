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

    /** Catégorie => [clé de configuration, durée par défaut en jours, libellé]. */
    const CATEGORIES = [
        'sessions' => ['UCPBRIDGE_RETENTION_SESSIONS', 30, 'Sessions de caisse et paniers terminés ou expirés'],
        'guests' => ['UCPBRIDGE_RETENTION_GUESTS', 30, 'Clients invités créés par le module, sans commande'],
        'oauth' => ['UCPBRIDGE_RETENTION_OAUTH', 30, 'Codes et jetons OAuth expirés ou révoqués'],
        'payments' => ['UCPBRIDGE_RETENTION_PAYMENTS', 3650, 'Journal des paiements (débits, remboursements)'],
        'proofs' => ['UCPBRIDGE_RETENTION_PROOFS', 3650, 'Journal de preuve et demandes de rétractation'],
    ];

    /**
     * Durées légales : plancher par catégorie (jours). Une durée plus courte est refusée à l'enregistrement et bloque la
     * purge ; 0 (jamais purgé) reste permis. 10 ans : pièces comptables (article L123-22 du Code de commerce) et
     * contrats conclus par voie électronique (articles L213-1 et D213-2 du Code de la consommation).
     */
    const LEGAL_MIN_DAYS = ['payments' => 3650, 'proofs' => 3650];

    /** Données personnelles d'une session, retirées d'une session terminée par une commande. */
    const PERSONAL_KEYS = ['buyer', 'consent', 'destination', 'terms_acceptance', 'context'];

    /** Lignes du compte rendu : clé => [libellé après purge, libellé en simulation]. */
    const REPORT = [
        'sessions_deleted' => ['Sessions et paniers UCP supprimés', 'Sessions et paniers UCP à supprimer'],
        'sessions_minimized' => ['Sessions terminées par une commande, données personnelles retirées',
            'Sessions terminées par une commande, données personnelles à retirer'],
        'carts' => ['Paniers PrestaShop sans commande supprimés', 'Paniers PrestaShop sans commande à supprimer'],
        'addresses' => ['Adresses sans client supprimées', 'Adresses sans client à supprimer'],
        'guests' => ['Clients invités sans commande supprimés', 'Clients invités sans commande à supprimer'],
        'oauth_codes' => ['Codes OAuth supprimés', 'Codes OAuth à supprimer'],
        'oauth_tokens' => ['Jetons OAuth supprimés', 'Jetons OAuth à supprimer'],
        'payments' => ['Opérations du journal des paiements supprimées', 'Opérations du journal des paiements à supprimer'],
        'proofs' => ['Preuves de commande supprimées', 'Preuves de commande à supprimer'],
        'withdrawals' => ['Demandes de rétractation supprimées', 'Demandes de rétractation à supprimer'],
        'idempotency' => ['Clés d\'idempotence de plus de 24 h supprimées', 'Clés d\'idempotence de plus de 24 h à supprimer'],
    ];

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
        return 'Durée de conservation trop courte (' . self::CATEGORIES[$cat][2] . ') : la loi impose de conserver ces pièces '
            . '10 ans. Saisissez au moins ' . self::LEGAL_MIN_DAYS[$cat] . ' jours, ou 0 pour ne jamais purger. Aucune durée modifiée.';
    }

    /** Message de refus de la purge (durées trop courtes en configuration), en ligne de commande et au back-office. */
    public static function purgeRefusedMessage(array $bad)
    {
        $list = [];
        foreach ($bad as $cat => $days) {
            $list[] = self::CATEGORIES[$cat][2] . ' : ' . (int) $days . ' jours, minimum légal ' . self::LEGAL_MIN_DAYS[$cat] . ' jours';
        }

        return 'Purge refusée, rien n\'a été supprimé : durée de conservation trop courte (' . implode(' ; ', $list) . ', ou 0 pour '
            . 'ne jamais purger). Corrigez les durées dans la configuration du module.';
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

    /** Compte rendu lisible : une ligne par catégorie, dans l'ordre de REPORT. */
    public static function summary(array $counts, $dryRun)
    {
        $lines = [];
        foreach (self::REPORT as $k => $label) {
            $lines[] = $label[$dryRun ? 1 : 0] . ' : ' . (int) ($counts[$k] ?? 0);
        }

        return $lines;
    }
}
