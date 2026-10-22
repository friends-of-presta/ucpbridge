<?php
/**
 * Point d'entrée REST UCP : /.well-known/ucp (profil public) et /ucp/v1/* (API authentifiée).
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpbridgeApiModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl = false;
    protected $content_only = true;

    /** @var Ucpbridge */
    public $module;

    /** @var array|null client authentifié par jeton OAuth : id_customer, client_id, scopes */
    private $user;

    /** @var string|null plateforme authentifiée par sa clé API (mode invité) */
    private $platform;

    /** @var string en-tête UCP-Agent (ou meta.ucp-agent en MCP) */
    private $agent = '';

    public function initContent()
    {
        // Rien à afficher : toute la réponse est produite dans postProcess().
    }

    public function display()
    {
        return true;
    }

    private function send($code, $doc, array $headers = [])
    {
        if (ob_get_length()) {
            ob_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $rid = $this->header('Request-Id');
        if ($rid) {
            header('Request-Id: ' . preg_replace('/[^A-Za-z0-9._:-]/', '', $rid));
        }
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        // Après les en-têtes : PHP force 401 à la pose de WWW-Authenticate, ce qui masquerait un 403 insufficient_scope.
        http_response_code((int) $code);
        echo json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    private function header($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if ('Authorization' === $name && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (0 === strcasecmp($k, $name)) {
                    return (string) $v;
                }
            }
        }

        return '';
    }

    private function profile()
    {
        $v = Ucpbridge::UCP_VERSION;
        $spec = 'https://ucp.dev/' . $v . '/specification/';
        $schema = 'https://ucp.dev/' . $v . '/schemas/shopping/';
        $cap = function ($name, $page, $file, $extends = null) use ($v, $spec, $schema) {
            $c = ['version' => $v, 'spec' => $spec . $page, 'schema' => $schema . $file];
            if ($extends) {
                $c['extends'] = $extends;
            }

            return [$c];
        };

        return [
            'ucp' => [
                'version' => $v,
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version' => $v,
                        'spec' => $spec . 'overview',
                        'transport' => 'rest',
                        'endpoint' => rtrim($this->context->link->getBaseLink(), '/') . '/ucp/v1',
                        'schema' => 'https://ucp.dev/' . $v . '/services/shopping/rest.openapi.json',
                    ], [
                        'version' => $v,
                        'spec' => $spec . 'overview',
                        'transport' => 'mcp',
                        'endpoint' => rtrim($this->context->link->getBaseLink(), '/') . '/ucp/v1/mcp',
                        'schema' => 'https://ucp.dev/' . $v . '/services/shopping/mcp.openrpc.json',
                    ]],
                ],
                'capabilities' => [
                    'dev.ucp.shopping.catalog.search' => $cap('catalog.search', 'catalog/search', 'catalog_search.json'),
                    'dev.ucp.shopping.cart' => $cap('cart', 'cart', 'cart.json'),
                    'dev.ucp.shopping.checkout' => $cap('checkout', 'checkout', 'checkout.json'),
                    'dev.ucp.shopping.fulfillment' => $cap('fulfillment', 'fulfillment', 'fulfillment.json', 'dev.ucp.shopping.checkout'),
                    'dev.ucp.shopping.order' => $cap('order', 'order', 'order.json'),
                    'dev.ucp.shopping.buyer_consent' => $cap('buyer_consent', 'buyer-consent', 'buyer_consent.json', 'dev.ucp.shopping.checkout'),
                    'dev.ucp.shopping.discount' => [[
                        'version' => $v, 'spec' => $spec . 'discount', 'schema' => $schema . 'discount.json',
                        'extends' => ['dev.ucp.shopping.cart', 'dev.ucp.shopping.checkout'],
                    ]],
                    'dev.ucp.common.identity_linking' => [[
                        'version' => $v,
                        'spec' => $spec . 'identity-linking',
                        'schema' => 'https://ucp.dev/' . $v . '/schemas/common/identity_linking.json',
                        'config' => [
                            'authorization_server' => rtrim($this->context->link->getBaseLink(), '/') . '/.well-known/oauth-authorization-server',
                            // Scopes optionnels : sans jeton client, la caisse reste ouverte aux invités.
                            'scopes' => (object) [],
                        ],
                    ]],
                ],
                'payment_handlers' => (object) UcpBridgeCart::paymentHandlers(),
            ],
            'signing_keys' => [],
        ];
    }

    public function postProcess()
    {
        $path = trim((string) Tools::getValue('ucp_path'), '/');
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);

        if ('profile' === $path || 'well-known' === $path) {
            if ('GET' !== $method) {
                $this->send(405, ['code' => 'method_not_allowed', 'content' => UcpBridgeI18n::api('GET only')]);
            }
            $this->send(200, $this->profile(), ['Access-Control-Allow-Origin' => '*']);
        }

        // Authentification. Deux jetons distincts, un seul en-tête Authorization: Bearer (RFC 6750, comme la spécification) :
        //  - jeton client OAuth (préfixe ucpat_) : identifie le client PrestaShop ET la plateforme (client OAuth
        //    qui l'a obtenu). Prioritaire. La clé API peut l'accompagner en X-UCP-Api-Key ; si présente, elle est vérifiée.
        //  - sinon clé API de la plateforme (préfixe ucpk_, une clé par plateforme) : caisse en invité.
        $auth = $this->header('Authorization');
        $bearer = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : '';
        $realm = rtrim($this->context->link->getBaseLink(), '/');
        $this->user = null;
        $this->platform = null;
        if (0 === strpos($bearer, UcpBridgeOAuth::ACCESS_PREFIX)) {
            $this->user = UcpBridgeOAuth::verifyAccess($bearer);
            if (!$this->user) {
                $this->send(401, ['messages' => [['type' => 'error', 'code' => 'identity_required', 'content' => UcpBridgeI18n::api('Invalid, expired or revoked customer token'), 'severity' => 'requires_buyer_review']]],
                    ['WWW-Authenticate' => 'Bearer realm="' . $realm . '", error="invalid_token"']);
            }
            $extra = $this->header('X-UCP-Api-Key');
            if ('' !== $extra && null === UcpBridgeApiKeys::authenticate($extra)) {
                $this->send(401, ['code' => 'unauthorized', 'content' => UcpBridgeI18n::api('Invalid X-UCP-Api-Key')], ['WWW-Authenticate' => 'Bearer realm="ucp"']);
            }
        } elseif (null === ($this->platform = UcpBridgeApiKeys::authenticate($bearer))) {
            $this->send(401, ['code' => 'unauthorized', 'content' => UcpBridgeI18n::api('Missing or invalid API key (Authorization: Bearer)')],
                ['WWW-Authenticate' => 'Bearer realm="ucp"']);
        }

        $this->agent = $this->header('UCP-Agent');
        $raw = (string) file_get_contents('php://input');
        $body = [];
        if ('' !== trim($raw) && 'mcp' !== $path) {
            $body = json_decode($raw, true);
            if (!is_array($body)) {
                $this->send(400, ['code' => 'invalid_json', 'content' => UcpBridgeI18n::api('Invalid JSON body')]);
            }
        }

        if ('mcp' === $path) {
            $this->mcp($method, $raw);
        }
        list($code, $doc, $headers) = $this->dispatch($method, $path, $body, trim($this->header('Idempotency-Key')));
        $this->send($code, $doc, $headers);
    }

    /**
     * Exécution commune au REST et au MCP : Idempotency-Key (cloisonnée par appelant) puis route métier.
     *
     * @return array [code HTTP, document, en-têtes]
     */
    /** Opérations qui modifient l'état : Idempotency-Key obligatoire (la recherche et la fiche produit sont des lectures). */
    public static function isMutation($method, $path)
    {
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && 0 !== strpos($path, 'catalog');
    }

    private function dispatch($method, $path, array $body, $idem)
    {
        // Scope du jeton client contrôlé avant toute réservation de clé (un refus ne bloque jamais la clé).
        if ($this->user) {
            $seg = explode('/', $path);
            $need = 'orders' === $seg[0] ? UcpBridgeOAuth::SCOPE_ORDER : UcpBridgeOAuth::SCOPE_CHECKOUT;
            if ('catalog' !== $seg[0] && !in_array($need, $this->user['scopes'], true)) {
                return [403, ['messages' => [['type' => 'error', 'code' => 'insufficient_scope', 'content' => UcpBridgeI18n::api('Scope required: %scope%', ['%scope%' => $need]), 'severity' => 'requires_buyer_review']]],
                    ['WWW-Authenticate' => 'Bearer realm="' . rtrim($this->context->link->getBaseLink(), '/') . '", error="insufficient_scope", scope="' . $need . '"']];
            }
        }
        if ('' === (string) $idem && self::isMutation($method, $path)) {
            return [400, ['code' => 'idempotency_key_required', 'content' => UcpBridgeI18n::api('Idempotency-Key header required for %method% /%path% (in MCP: arguments.meta["idempotency-key"])', ['%method%' => $method, '%path%' => $path])], []];
        }
        if ('' === (string) $idem || 'GET' === $method) {
            try {
                list($code, $doc) = $this->route($method, $path, $body);
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('ucpbridge : ' . $e->getMessage(), 3);

                return [500, ['code' => 'internal_error', 'content' => UcpBridgeI18n::api('Internal error')], []];
            }

            return [$code, $doc, []];
        }
        if (strlen($idem) > 255) {
            return [400, ['code' => 'invalid_idempotency_key', 'content' => UcpBridgeI18n::api('Idempotency-Key too long')], []];
        }
        $hash = UcpBridgeIdempotency::fingerprint($method, $path, $body);
        // Clé cloisonnée par appelant : deux plateformes (chacune sa clé API, ou chacune son client OAuth) ou un invité et
        // un client ne partagent jamais une réponse.
        $key = UcpBridgeIdempotency::scopedKey($this->callerId(), $idem);
        $cached = UcpBridgeIdempotency::begin($key, $hash);
        if (null !== $cached) {
            return [$cached[0], $cached[1], !empty($cached[2]) ? ['Idempotent-Replayed' => 'true'] : []];
        }
        try {
            list($code, $doc) = $this->route($method, $path, $body);
        } catch (Throwable $e) {
            UcpBridgeIdempotency::finish($key, 500, null);
            PrestaShopLogger::addLog('ucpbridge : ' . $e->getMessage(), 3);

            return [500, ['code' => 'internal_error', 'content' => UcpBridgeI18n::api('Internal error')], []];
        }
        UcpBridgeIdempotency::finish($key, $code, $doc);

        return [$code, $doc, []];
    }

    /** Transport MCP (POST /ucp/v1/mcp) : mêmes routes, même authentification, même idempotence que le REST. */
    private function mcp($method, $raw)
    {
        // Protection contre le rebinding DNS (recommandation MCP) : une requête de navigateur venue d'une autre origine
        // que la boutique est refusée. Les clients serveur (sans en-tête Origin) ne sont pas concernés.
        $origin = $this->header('Origin');
        if ('' !== $origin && !self::sameOrigin($origin, $this->context->link->getBaseLink())) {
            $this->send(403, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => UcpBridgeI18n::api('Origin refused')]]);
        }
        if ('POST' !== $method) {
            // Pas de flux SSE côté serveur : réponses JSON uniquement (HTTP streamable, mode sans session).
            $this->send(405, ['code' => 'method_not_allowed', 'content' => UcpBridgeI18n::api('POST only (JSON-RPC 2.0)')], ['Allow' => 'POST']);
        }
        $server = new UcpBridgeMcp(function ($m, $p, array $b, $idem, $agent) {
            $this->agent = $agent;
            list($code, $doc) = $this->dispatch($m, $p, $b, (string) $idem);

            return [$code, $doc];
        }, ['name' => 'ucpbridge', 'title' => Ucpbridge::DISPLAY_NAME, 'version' => $this->module->version]);
        $r = $server->handle($raw);
        if (null === $r['body']) {
            if (ob_get_length()) {
                ob_clean();
            }
            http_response_code((int) $r['status']);
            exit;
        }
        $this->send($r['status'], $r['body']);
    }

    public static function sameOrigin($origin, $base)
    {
        $o = parse_url((string) $origin);
        $b = parse_url((string) $base);
        $port = function ($u) {
            return (int) ($u['port'] ?? ('https' === ($u['scheme'] ?? '') ? 443 : 80));
        };

        return is_array($o) && is_array($b) && isset($o['host'], $b['host'])
            && strtolower($o['scheme'] ?? '') === strtolower($b['scheme'] ?? '')
            && strtolower($o['host']) === strtolower($b['host']) && $port($o) === $port($b);
    }

    /**
     * Identité de l'appelant (propriétaire des paniers et sessions, espace des clés d'idempotence) : client OAuth +
     * client final, ou plateforme invitée identifiée par sa propre clé API.
     */
    private function callerId()
    {
        return $this->user ? 'user:' . $this->user['client_id'] . ':' . (int) $this->user['id_customer']
            : UcpBridgeApiKeys::callerId((string) $this->platform);
    }

    private function route($method, $path, array $body)
    {
        $svc = new UcpBridgeCart($this->context, $this->module);
        $svc->setAgent($this->agent);
        $svc->setCaller($this->callerId());
        $seg = explode('/', $path);
        if ($this->user) {
            $svc->setUser($this->user['id_customer']);
        }
        $notFound = [404, ['code' => 'not_found', 'content' => UcpBridgeI18n::api('Unknown resource: %method% /%path%', ['%method%' => $method, '%path%' => $path])]];

        switch ($seg[0]) {
            case 'catalog':
                if ('POST' === $method && 'search' === ($seg[1] ?? '')) {
                    $limit = (int) ($body['pagination']['limit'] ?? ($body['limit'] ?? 10));
                    $products = $svc->catalog()->search((string) ($body['query'] ?? ''), $limit ?: 10);

                    return [200, [
                        'ucp' => ['version' => Ucpbridge::UCP_VERSION, 'status' => 'success',
                            'capabilities' => ['dev.ucp.shopping.catalog.search' => [['version' => Ucpbridge::UCP_VERSION]]]],
                        'products' => $products,
                        'pagination' => ['has_next_page' => false, 'total_count' => count($products)],
                    ]];
                }
                if ('POST' === $method && 'product' === ($seg[1] ?? '')) {
                    return [200, $svc->productDoc((string) ($body['id'] ?? ''))];
                }

                return $notFound;

            case 'carts':
                if (!isset($seg[1])) {
                    return 'POST' === $method ? $svc->createCart($body) : $notFound;
                }
                $s = $svc->load($seg[1], 'cart');
                if (!$s || !$svc->ownedByCaller($s)) {
                    return [404, $svc->errorDoc([['type' => 'error', 'code' => 'not_found', 'content' => UcpBridgeI18n::api('Cart not found'), 'severity' => 'unrecoverable']], ['dev.ucp.shopping.cart'])];
                }
                if (!isset($seg[2])) {
                    if ('GET' === $method) {
                        return [200, $svc->cartDoc($s)];
                    }
                    if ('PUT' === $method) {
                        return $svc->updateCart($s, $body);
                    }
                } elseif ('cancel' === $seg[2] && 'POST' === $method) {
                    return $svc->cancelCart($s);
                }

                return $notFound;

            case 'checkout-sessions':
                if (!isset($seg[1])) {
                    return 'POST' === $method ? $svc->createCheckout($body) : $notFound;
                }
                $s = $svc->load($seg[1], 'checkout');
                if (!$s || !$svc->ownedByCaller($s)) {
                    return [404, $svc->errorDoc([['type' => 'error', 'code' => 'not_found', 'content' => UcpBridgeI18n::api('Checkout session not found'), 'severity' => 'unrecoverable']], ['dev.ucp.shopping.checkout'])];
                }
                if (!isset($seg[2])) {
                    if ('GET' === $method) {
                        return [200, $svc->checkoutDoc($s)];
                    }
                    if ('PUT' === $method) {
                        return $svc->updateCheckout($s, $body);
                    }
                } elseif ('complete' === $seg[2] && 'POST' === $method) {
                    return $svc->completeCheckout($s, $body);
                } elseif ('cancel' === $seg[2] && 'POST' === $method) {
                    return $svc->cancelCheckout($s);
                }

                return $notFound;

            case 'orders':
                if ('GET' === $method && isset($seg[1]) && !isset($seg[2])) {
                    // Identifiant opaque uniquement (ord_...) : un id_order séquentiel ne mène à rien.
                    return $svc->orderDoc((string) $seg[1]);
                }

                return $notFound;
        }

        return $notFound;
    }
}
