<?php
/**
 * Transport MCP du service dev.ucp.shopping (JSON-RPC 2.0 sur HTTP streamable, réponses JSON).
 * Aucune logique métier ici : chaque outil est traduit vers la même route que l'API REST
 * (routeur injecté), puis la réponse UCP est renvoyée en structuredContent et en texte.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeMcp
{
    /** Versions du protocole MCP acceptées, la plus récente en premier. */
    const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    const UCP_SCHEMAS = 'https://ucp.dev/2026-04-08/schemas/shopping/';

    /**
     * Outils UCP (binding MCP, mcp.openrpc.json) : méthode HTTP, chemin REST, objet de charge utile,
     * identifiant requis, Idempotency-Key requise. Description lisible : description().
     */
    const TOOLS = [
        'search_catalog' => ['POST', 'catalog/search', 'catalog', false, false],
        'get_product' => ['POST', 'catalog/product', 'catalog', false, false],
        'create_cart' => ['POST', 'carts', 'cart', false, true],
        'get_cart' => ['GET', 'carts/{id}', null, true, false],
        'update_cart' => ['PUT', 'carts/{id}', 'cart', true, true],
        'cancel_cart' => ['POST', 'carts/{id}/cancel', null, true, true],
        'create_checkout' => ['POST', 'checkout-sessions', 'checkout', false, true],
        'get_checkout' => ['GET', 'checkout-sessions/{id}', null, true, false],
        'update_checkout' => ['PUT', 'checkout-sessions/{id}', 'checkout', true, true],
        'complete_checkout' => ['POST', 'checkout-sessions/{id}/complete', 'checkout', true, true],
        'cancel_checkout' => ['POST', 'checkout-sessions/{id}/cancel', null, true, true],
        'get_order' => ['GET', 'orders/{id}', null, true, false],
    ];

    /** Description traduite d'un outil (lue par l'agent dans tools/list). */
    public static function description($name)
    {
        switch ($name) {
            case 'search_catalog':
                return UcpBridgeI18n::api('Searches the catalog (free text, catalog.query). Returns the products with their variants, prices and availability.');
            case 'get_product':
                return UcpBridgeI18n::api('Product details by identifier (catalog.id: product reference, variant reference or {product_id}-{variant_id}), with all its variants.');
            case 'create_cart':
                return UcpBridgeI18n::api('Creates a transferable cart (cart.line_items). meta.idempotency-key required. Returns the cart and its continue_url: signed link that opens this cart on the shop.');
            case 'get_cart':
                return UcpBridgeI18n::api('Reads a cart and its continue_url.');
            case 'update_cart':
                return UcpBridgeI18n::api('Replaces the content of a cart (line_items, discounts.codes).');
            case 'cancel_cart':
                return UcpBridgeI18n::api('Cancels a cart.');
            case 'create_checkout':
                return UcpBridgeI18n::api('Creates a checkout session (line_items or cart_id, buyer, fulfillment, discounts).');
            case 'get_checkout':
                return UcpBridgeI18n::api('Reads a checkout session: status, totals, shipping, legal links, disclosures, continue_url.');
            case 'update_checkout':
                return UcpBridgeI18n::api('Updates a checkout session (buyer, address and carrier, discount codes, consents).');
            case 'complete_checkout':
                return UcpBridgeI18n::api('Completes the session and places the order (checkout.payment.instruments). meta.idempotency-key required.');
            case 'cancel_checkout':
                return UcpBridgeI18n::api('Cancels a checkout session. meta.idempotency-key required.');
            case 'get_order':
                return UcpBridgeI18n::api('Reads an order placed through UCP.');
        }

        return (string) $name;
    }

    const SCHEMA_FILES = ['catalog' => 'catalog_search.json', 'cart' => 'cart.json', 'checkout' => 'checkout.json'];

    /** @var callable function ($method, $path, array $body, $idempotencyKey, $agent): [code HTTP, document UCP] */
    private $router;
    /** @var array */
    private $serverInfo;

    public function __construct(callable $router, array $serverInfo)
    {
        $this->router = $router;
        $this->serverInfo = $serverInfo;
    }

    private static function reply($id, $result)
    {
        return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    private static function error($id, $code, $message, $data = null, $status = 200)
    {
        $err = ['code' => (int) $code, 'message' => (string) $message];
        if (null !== $data) {
            $err['data'] = $data;
        }

        return ['status' => (int) $status, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err]];
    }

    public function handle($raw)
    {
        $req = json_decode((string) $raw, true);
        if (!is_array($req)) {
            return self::error(null, -32700, 'Parse error', null, 400);
        }
        if (array_keys($req) === range(0, count($req) - 1)) {
            return self::error(null, -32600, UcpBridgeI18n::api('JSON-RPC batches are not supported'), null, 400);
        }
        $id = $req['id'] ?? null;
        $isNotification = !array_key_exists('id', $req);
        if ('2.0' !== ($req['jsonrpc'] ?? null) || !is_string($req['method'] ?? null)) {
            return self::error($id, -32600, 'Invalid Request', null, 400);
        }
        if ($isNotification) {
            return ['status' => 202, 'body' => null];
        }
        $params = is_array($req['params'] ?? null) ? $req['params'] : [];
        switch ($req['method']) {
            case 'initialize':
                $asked = (string) ($params['protocolVersion'] ?? '');

                return self::reply($id, [
                    'protocolVersion' => in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => $this->serverInfo,
                    'instructions' => UcpBridgeI18n::api('UCP shop (Universal Commerce Protocol 2026-04-08). Every tool requires arguments.meta["ucp-agent"].profile. Variants are mandatory: if a product has some, specify the variant (otherwise variant_required). Tools that change state (create_*, update_*, cancel_*, complete_checkout) require meta["idempotency-key"]. create_cart returns a continue_url to be opened by the buyer; a checkout session whose continue_url is opened can no longer be completed through the API.'),
                ]);
            case 'ping':
                return self::reply($id, new stdClass());
            case 'tools/list':
                return self::reply($id, ['tools' => $this->tools()]);
            case 'tools/call':
                return $this->call($id, $params);
        }

        return self::error($id, -32601, 'Method not found: ' . $req['method']);
    }

    public function tools()
    {
        $out = [];
        foreach (self::TOOLS as $name => $t) {
            list(, , $payload, $needsId, $needsIdem) = $t;
            $meta = [
                'type' => 'object',
                'description' => UcpBridgeI18n::api('UCP metadata: ucp-agent.profile (URL of the platform profile), idempotency-key (UUID)'),
                'properties' => [
                    'ucp-agent' => ['type' => 'object', 'properties' => ['profile' => ['type' => 'string']], 'required' => ['profile']],
                    'idempotency-key' => ['type' => 'string'],
                ],
                'required' => $needsIdem ? ['ucp-agent', 'idempotency-key'] : ['ucp-agent'],
            ];
            $props = ['meta' => $meta];
            $required = ['meta'];
            if ($needsId) {
                $props['id'] = ['type' => 'string', 'description' => UcpBridgeI18n::api('Resource identifier')];
                $required[] = 'id';
            }
            if ($payload) {
                $props[$payload] = ['type' => 'object', 'description' => UcpBridgeI18n::api('UCP object, schema %schema%', ['%schema%' => self::UCP_SCHEMAS . self::SCHEMA_FILES[$payload]])];
                $required[] = $payload;
            }
            $out[] = [
                'name' => $name,
                'description' => self::description($name),
                'inputSchema' => ['type' => 'object', 'properties' => $props, 'required' => $required],
                'outputSchema' => ['type' => 'object'],
            ];
        }

        return $out;
    }

    private function call($id, array $params)
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (!isset(self::TOOLS[$name])) {
            return self::error($id, -32602, UcpBridgeI18n::api('Unknown tool: %name%', ['%name%' => $name]));
        }
        list($method, $path, $payload, $needsId, $needsIdem) = self::TOOLS[$name];
        $meta = is_array($args['meta'] ?? null) ? $args['meta'] : [];
        $profile = $meta['ucp-agent']['profile'] ?? null;
        if (!is_string($profile) || '' === trim($profile)) {
            return self::error($id, -32602, UcpBridgeI18n::api('meta["ucp-agent"].profile is required'));
        }
        $idem = isset($meta['idempotency-key']) && is_string($meta['idempotency-key']) ? trim($meta['idempotency-key']) : '';
        if ($needsIdem && '' === $idem) {
            return self::error($id, -32602, UcpBridgeI18n::api('meta["idempotency-key"] is required for %name%', ['%name%' => $name]));
        }
        if ($needsId) {
            $rid = $args['id'] ?? null;
            if (!is_scalar($rid) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $rid)) {
                return self::error($id, -32602, UcpBridgeI18n::api('id missing or invalid'));
            }
            $path = str_replace('{id}', (string) $rid, $path);
        }
        $body = [];
        if ($payload) {
            $body = is_array($args[$payload] ?? null) ? $args[$payload] : [];
        }
        $agent = 'profile="' . str_replace(['"', '\\', "\r", "\n"], '', $profile) . '"';
        list($code, $doc) = call_user_func($this->router, $method, $path, $body, '' !== $idem ? $idem : null, $agent);

        if ($code >= 500) {
            return self::error($id, -32603, UcpBridgeI18n::api('Internal error'), $doc, 500);
        }
        if ($code >= 300) {
            // Erreurs protocolaires (authentification, conflit d'idempotence, ressource inconnue) : JSON-RPC error.
            return self::error($id, 400 === (int) $code ? -32602 : -32000, (string) ($doc['content'] ?? ($doc['code'] ?? UcpBridgeI18n::api('Error'))), $doc, $code);
        }

        return self::reply($id, [
            'structuredContent' => $doc,
            'content' => [['type' => 'text', 'text' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'isError' => 'error' === ($doc['ucp']['status'] ?? null),
        ]);
    }
}
