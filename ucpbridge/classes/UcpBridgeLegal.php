<?php
/**
 * Liens légaux (links) et mentions obligatoires des réponses UCP, logique pure.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeLegal
{
    const TYPES = ['terms_of_service', 'privacy_policy', 'refund_policy', 'shipping_policy'];

    /** États membres de l'Union européenne (codes ISO 3166-1 alpha-2). */
    const EU = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU',
        'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    const KEYWORDS = [
        'terms_of_service' => '/\b(cgv|conditions?)\b/u',
        'privacy_policy' => '/(confidentialit|privacy|donnees|données|rgpd|mentions)/u',
        'refund_policy' => '/(retour|rembourse|r[ée]tractation|refund)/u',
        'shipping_policy' => '/(livraison|exp[ée]dition|shipping|delivery)/u',
    ];

    /**
     * Correspondance par défaut type => id de page CMS, devinée à l'installation.
     * CGV : la page CMS des conditions de la boutique (PS_CONDITIONS_CMS_ID) si elle existe.
     * Remboursement : à défaut de page dédiée, les CGV (qui portent le droit de rétractation).
     *
     * @param array $pages id => [title, rewrite, url, active]
     */
    public static function defaultMapping(array $pages, $conditionsId)
    {
        $map = array_fill_keys(self::TYPES, 0);
        if ($conditionsId && isset($pages[(int) $conditionsId])) {
            $map['terms_of_service'] = (int) $conditionsId;
        }
        foreach (self::TYPES as $type) {
            if ($map[$type]) {
                continue;
            }
            foreach ($pages as $id => $p) {
                $hay = mb_strtolower($p['title'] . ' ' . str_replace('-', ' ', (string) $p['rewrite']), 'UTF-8');
                if (preg_match(self::KEYWORDS[$type], $hay)) {
                    $map[$type] = (int) $id;
                    break;
                }
            }
        }
        if (!$map['refund_policy']) {
            $map['refund_policy'] = $map['terms_of_service'];
        }

        return $map;
    }

    /** Liens UCP {type, url, title} dans l'ordre des types, pages absentes ou inactives ignorées. */
    public static function links(array $map, array $pages)
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $id = (int) ($map[$type] ?? 0);
            if (!$id || empty($pages[$id]) || empty($pages[$id]['active'])) {
                continue;
            }
            $out[] = ['type' => $type, 'url' => (string) $pages[$id]['url'], 'title' => (string) $pages[$id]['title']];
        }

        return $out;
    }

    public static function isEu($iso)
    {
        return in_array(strtoupper((string) $iso), self::EU, true);
    }

    /** Mention du droit de rétractation (UE) : avertissement à présentation « disclosure ». */
    public static function withdrawalDisclosure($iso, $url)
    {
        if (!self::isEu($iso)) {
            return null;
        }

        return [
            'type' => 'warning',
            'code' => 'withdrawal_right',
            'content' => UcpBridgeI18n::api('Right of withdrawal: you have 14 days from receipt of your order to withdraw, without having to give any reason. An online withdrawal function is available from the order confirmation e-mail.'),
            'presentation' => 'disclosure',
            'url' => (string) $url,
        ];
    }

    /** Version des CGV : page, date de mise à jour et empreinte du contenu présenté. */
    public static function termsVersion($idCms, $dateUpd, $content)
    {
        return 'cms' . (int) $idCms . '-' . substr(hash('sha256', (string) $dateUpd . '|' . (string) $content), 0, 12);
    }
}
