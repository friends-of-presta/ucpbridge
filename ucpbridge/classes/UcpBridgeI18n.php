<?php
/**
 * Traduction des chaînes destinées à un humain, par le traducteur de PrestaShop (nouveau système, domaines
 * Modules.Ucpbridge.*). Textes sources en anglais, traductions dans translations/<locale>/ (XLIFF). Les codes UCP
 * renvoyés aux agents ne passent jamais par ici : seuls les messages lisibles sont traduits.
 *
 * Hors PrestaShop (tests unitaires), le texte source est renvoyé avec ses paramètres remplacés.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeI18n
{
    /** Back-office : configuration, fiche commande, comptes rendus de purge et de rapprochement. */
    const ADMIN = 'Modules.Ucpbridge.Admin';
    /** Boutique : pages du module, e-mails, compte client. */
    const SHOP = 'Modules.Ucpbridge.Shop';
    /** Messages lisibles renvoyés aux agents (REST et MCP), à côté de leur code stable. */
    const API = 'Modules.Ucpbridge.Api';

    /**
     * @param string $id texte source (anglais), paramètres sous la forme %nom%
     * @param string|null $locale locale PrestaShop (fr-FR, en-US) ; null : langue du contexte
     */
    public static function trans($id, array $parameters = [], $domain = self::SHOP, $locale = null)
    {
        if (class_exists('Context', false)) {
            $context = Context::getContext();
            if ($context && method_exists($context, 'getTranslator')) {
                try {
                    return $context->getTranslator()->trans($id, $parameters, $domain, $locale);
                } catch (Throwable $e) {
                    // Traducteur indisponible (contexte partiel) : texte source.
                }
            }
        }

        return strtr($id, $parameters);
    }

    /** Back-office (domaine Modules.Ucpbridge.Admin). */
    public static function admin($id, array $parameters = [], $locale = null)
    {
        return self::trans($id, $parameters, self::ADMIN, $locale);
    }

    /** Boutique : pages, e-mails, compte client (domaine Modules.Ucpbridge.Shop). */
    public static function shop($id, array $parameters = [], $locale = null)
    {
        return self::trans($id, $parameters, self::SHOP, $locale);
    }

    /** Messages lisibles des réponses aux agents (domaine Modules.Ucpbridge.Api). */
    public static function api($id, array $parameters = [], $locale = null)
    {
        return self::trans($id, $parameters, self::API, $locale);
    }

    /** Locale PrestaShop d'une langue (fr-FR, en-US), null si inconnue. */
    public static function localeOf($idLang)
    {
        if (!class_exists('Language', false) || !(int) $idLang) {
            return null;
        }
        $lang = new Language((int) $idLang);

        return Validate::isLoadedObject($lang) && !empty($lang->locale) ? (string) $lang->locale : null;
    }
}
