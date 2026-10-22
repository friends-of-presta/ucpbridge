<?php
/**
 * Points d'entrée OAuth 2.0 de l'identity linking UCP :
 *   GET  /.well-known/oauth-authorization-server   métadonnées RFC 8414
 *   GET  /ucp/oauth/authorize                       connexion client PrestaShop puis consentement
 *   POST /ucp/oauth/authorize                       décision (autoriser / refuser)
 *   POST /ucp/oauth/token                           code + PKCE, ou refresh_token
 *   POST /ucp/oauth/revoke                          révocation RFC 7009
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpbridgeOauthModuleFrontController extends ModuleFrontController
{
    public $ssl = false;
    protected $content_only = true;

    /** @var Ucpbridge */
    public $module;

    public function initContent()
    {
        $action = (string) Tools::getValue('ucp_oauth');
        switch ($action) {
            case 'metadata':
                $this->json(200, UcpBridgeOAuth::metadata($this->context->link), ['Access-Control-Allow-Origin' => '*']);
                // no break
            case 'authorize':
                $this->authorize();
                // no break
            case 'token':
                $this->token();
                // no break
            case 'revoke':
                $this->revoke();
        }
        $this->json(404, ['error' => 'not_found']);
    }

    public function display()
    {
        return true;
    }

    private function json($code, $doc, array $headers = [])
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code((int) $code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    private function esc($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /* ------------------------------ Autorisation ------------------------------ */

    private function params()
    {
        $p = [];
        foreach (['response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method'] as $k) {
            $p[$k] = (string) Tools::getValue($k);
        }

        return $p;
    }

    private function back($redirectUri, array $q)
    {
        $sep = false === strpos($redirectUri, '?') ? '?' : '&';
        header('Cache-Control: no-store');
        header('Location: ' . $redirectUri . $sep . http_build_query($q), true, 302);
        exit;
    }

    private function csrf(array $p, $idCustomer)
    {
        return hash_hmac('sha256', implode('|', [$idCustomer, $p['client_id'], $p['redirect_uri'], $p['code_challenge'], $p['state'], $p['scope']]),
            (string) Configuration::get('UCPBRIDGE_LINK_SECRET'));
    }

    private function authorize()
    {
        $p = $this->params();
        $client = UcpBridgeOAuth::client($p['client_id']);
        // Client ou redirect_uri inconnus : on n'envoie JAMAIS l'utilisateur vers l'URI fournie.
        if (!$client || !UcpBridgeOAuth::redirectAllowed($client, $p['redirect_uri'])) {
            $this->page(UcpBridgeI18n::shop('Request refused'), '<p>' . $this->esc(UcpBridgeI18n::shop('Unknown application or return address not registered.')) . '</p>', 400);
        }
        $state = '' !== $p['state'] ? ['state' => $p['state']] : [];
        if ('code' !== $p['response_type']) {
            $this->back($p['redirect_uri'], ['error' => 'unsupported_response_type'] + $state);
        }
        if (!UcpBridgePkce::isValidChallenge($p['code_challenge']) || 'S256' !== $p['code_challenge_method']) {
            $this->back($p['redirect_uri'], ['error' => 'invalid_request', 'error_description' => UcpBridgeI18n::api('PKCE S256 required')] + $state);
        }
        $scope = UcpBridgeOAuth::filterScope($p['scope']);
        if ('' === $scope) {
            $this->back($p['redirect_uri'], ['error' => 'invalid_scope'] + $state);
        }
        $p['scope'] = $scope;

        $customer = $this->context->customer;
        if (!$customer || !$customer->isLogged() || $customer->is_guest) {
            // Connexion PrestaShop standard, puis retour ici avec les mêmes paramètres.
            $self = rtrim($this->context->link->getBaseLink(), '/') . '/ucp/oauth/authorize?' . http_build_query($this->params());
            Tools::redirect($this->context->link->getPageLink('authentication', true, null, ['back' => $self]));
        }

        if ('POST' === $_SERVER['REQUEST_METHOD']) {
            if (!hash_equals($this->csrf($p, (int) $customer->id), (string) Tools::getValue('csrf'))) {
                $this->page(UcpBridgeI18n::shop('Request expired'), '<p>' . $this->esc(UcpBridgeI18n::shop('The form has expired, start again from the assistant.')) . '</p>', 400);
            }
            if ('allow' !== Tools::getValue('decision')) {
                $this->back($p['redirect_uri'], ['error' => 'access_denied'] + $state);
            }
            $code = UcpBridgeOAuth::issueCode($client['client_id'], (int) $customer->id, $p['redirect_uri'], $p['code_challenge'], $scope);
            $this->back($p['redirect_uri'], ['code' => $code] + $state);
        }
        $this->consent($p, $client, $customer);
    }

    private function consent(array $p, array $client, Customer $customer)
    {
        $labels = UcpBridgeOAuth::scopes();
        $items = '';
        foreach (explode(' ', $p['scope']) as $s) {
            $items .= '<li>' . $this->esc($labels[$s] ?? $s) . '</li>';
        }
        $hidden = '';
        foreach ($p as $k => $v) {
            $hidden .= '<input type="hidden" name="' . $this->esc($k) . '" value="' . $this->esc($v) . '">';
        }
        $hidden .= '<input type="hidden" name="csrf" value="' . $this->esc($this->csrf($p, (int) $customer->id)) . '">';
        $shop = $this->esc(Configuration::get('PS_SHOP_NAME'));
        $app = $this->esc($client['name'] ?? $client['client_id']);
        $action = $this->esc(rtrim($this->context->link->getBaseLink(), '/') . '/ucp/oauth/authorize');
        // Textes traduits échappés, puis mise en gras des noms (déjà échappés) par remplacement.
        $body = '<p class="who">' . strtr($this->esc(UcpBridgeI18n::shop('Logged in as %name% (%email%)')),
                ['%name%' => '<strong>' . $this->esc($customer->firstname . ' ' . $customer->lastname) . '</strong>', '%email%' => $this->esc($customer->email)]) . '</p>'
            . '<p>' . strtr($this->esc(UcpBridgeI18n::shop('%app% wants to link your %shop% account to:')),
                ['%app%' => '<strong>' . $app . '</strong>', '%shop%' => $shop]) . '</p>'
            . '<ul>' . $items . '</ul>'
            . '<p class="note">' . $this->esc(UcpBridgeI18n::shop('You can withdraw this access at any time from your account, section "My agent access". The assistant never sees your password.')) . '</p>'
            . '<form method="post" action="' . $action . '">' . $hidden
            . '<div class="actions"><button name="decision" value="deny" class="deny">' . $this->esc(UcpBridgeI18n::shop('Refuse')) . '</button>'
            . '<button name="decision" value="allow" class="allow">' . $this->esc(UcpBridgeI18n::shop('Allow')) . '</button></div></form>';
        $this->page(UcpBridgeI18n::shop('Allow %app%?', ['%app%' => $client['name'] ?? $client['client_id']]), $body, 200);
    }

    private function page($title, $body, $code)
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
        $shop = $this->esc(Configuration::get('PS_SHOP_NAME'));
        $lang = $this->context->language;
        echo '<!doctype html><html lang="' . $this->esc($lang && $lang->iso_code ? $lang->iso_code : 'en') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->esc($title) . '</title><style>'
            . 'body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f4f7;color:#1d1d24}'
            . '.card{max-width:440px;margin:48px auto;background:#fff;border-radius:14px;padding:28px 28px 24px;box-shadow:0 4px 24px rgba(0,0,0,.08)}'
            . '.shop{font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#6b6b78}'
            . 'h1{font-size:22px;margin:6px 0 18px}ul{padding-left:20px}li{margin:6px 0}'
            . '.who{background:#f4f4f7;border-radius:8px;padding:8px 12px;font-size:14px}.note{font-size:13px;color:#6b6b78}'
            . '.actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px}'
            . 'button{font:inherit;border-radius:8px;padding:10px 18px;cursor:pointer;border:1px solid #c9c9d2;background:#fff}'
            . 'button.allow{background:#31249F;border-color:#31249F;color:#fff;font-weight:600}'
            . '</style></head><body><div class="card"><div class="shop">' . $shop . '</div><h1>' . $this->esc($title) . '</h1>'
            . $body . '</div></body></html>';
        exit;
    }

    /* ------------------------------ Jetons ------------------------------ */

    private function clientFromRequest()
    {
        $user = $pass = '';
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Basic\s+(\S+)$/i', $auth, $m)) {
            $dec = (string) base64_decode($m[1], true);
            if (false !== strpos($dec, ':')) {
                list($user, $pass) = explode(':', $dec, 2);
            }
        } elseif (!empty($_SERVER['PHP_AUTH_USER'])) {
            $user = (string) $_SERVER['PHP_AUTH_USER'];
            $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
        }
        $c = UcpBridgeOAuth::authenticateClient($user, $pass, Tools::getValue('client_id'), Tools::getValue('client_secret'));
        if (!$c) {
            $this->json(401, ['error' => 'invalid_client'], ['WWW-Authenticate' => 'Basic realm="ucp-oauth"']);
        }

        return $c;
    }

    private function token()
    {
        if ('POST' !== $_SERVER['REQUEST_METHOD']) {
            $this->json(405, ['error' => 'invalid_request', 'error_description' => UcpBridgeI18n::api('POST only')]);
        }
        $client = $this->clientFromRequest();
        $grant = (string) Tools::getValue('grant_type');
        if ('authorization_code' === $grant) {
            list($err, $doc) = UcpBridgeOAuth::redeemCode($client, (string) Tools::getValue('code'),
                (string) Tools::getValue('redirect_uri'), (string) Tools::getValue('code_verifier'));
        } elseif ('refresh_token' === $grant) {
            list($err, $doc) = UcpBridgeOAuth::refresh($client, (string) Tools::getValue('refresh_token'));
        } else {
            $this->json(400, ['error' => 'unsupported_grant_type']);
        }
        if ($err) {
            $this->json(400, ['error' => $err, 'error_description' => $doc]);
        }
        $this->json(200, $doc);
    }

    private function revoke()
    {
        if ('POST' !== $_SERVER['REQUEST_METHOD']) {
            $this->json(405, ['error' => 'invalid_request']);
        }
        $client = $this->clientFromRequest();
        UcpBridgeOAuth::revoke($client, (string) Tools::getValue('token'));
        $this->json(200, new stdClass());
    }
}
