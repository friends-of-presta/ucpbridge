<?php
/**
 * Pages autonomes du module (relecture des CGV, rétractation) : gabarit HTML minimal, sorties échappées,
 * aucune ressource externe, en-têtes de sécurité.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgePage
{
    public static function e($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    public static function euros($cents)
    {
        return number_format(((int) $cents) / 100, 2, ',', "\u{202F}") . "\u{00A0}€";
    }

    /**
     * @param string $bodyHtml contenu déjà échappé par l'appelant
     * @param string $page code stable de la page (attribut data-ucp-page), lu par les tests à la place des libellés
     */
    public static function render($title, $bodyHtml, $code = 200, $page = '')
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code((int) $code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");
        $shop = self::e(Configuration::get('PS_SHOP_NAME'));
        $lang = Context::getContext()->language;
        echo '<!doctype html><html lang="' . self::e($lang && $lang->iso_code ? $lang->iso_code : 'en') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::e($title) . ' | ' . self::e(Ucpbridge::DISPLAY_NAME) . '</title><style>'
            . 'body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f4f7;color:#1d1d24}'
            . '.card{max-width:720px;margin:32px auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 24px rgba(0,0,0,.08)}'
            . '.shop{font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#6b6b78}h1{font-size:22px;margin:6px 0 18px}'
            . 'h2{font-size:17px;margin:22px 0 8px}table{width:100%;border-collapse:collapse}td{padding:6px 4px;border-bottom:1px solid #eee}'
            . 'td.r{text-align:right;white-space:nowrap}tr.total td{font-weight:700;border-bottom:0}'
            . '.terms{max-height:280px;overflow:auto;border:1px solid #ddd;border-radius:8px;padding:12px 16px;font-size:14px;background:#fafafa}'
            . '.check{display:flex;gap:10px;align-items:flex-start;margin:18px 0;font-weight:600}.check input{width:20px;height:20px;margin-top:2px}'
            . '.err{background:#fde8e8;color:#8a1c1c;border-radius:8px;padding:10px 14px}.ok{background:#e7f6ec;color:#1c5c33;border-radius:8px;padding:10px 14px}'
            . '.note{font-size:13px;color:#6b6b78}button{font:inherit;border-radius:8px;padding:11px 20px;cursor:pointer;border:1px solid #31249F;'
            . 'background:#31249F;color:#fff;font-weight:600}'
            . '</style></head><body><main class="card" data-ucp-page="' . self::e($page) . '"><div class="shop">' . $shop . '</div><h1>' . self::e($title) . '</h1>'
            . $bodyHtml . '</main></body></html>';
        exit;
    }
}
