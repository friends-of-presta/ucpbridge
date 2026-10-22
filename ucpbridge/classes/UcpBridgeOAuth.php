<?php
/**
 * Serveur d'autorisation OAuth 2.0 minimal pour dev.ucp.common.identity_linking :
 * code d'autorisation + PKCE S256 obligatoire, clients enregistrés en configuration
 * (client_secret_basic ou client_secret_post), jetons opaques stockés hachés (SHA-256),
 * expiration, révocation (RFC 7009), jeton de rafraîchissement à usage unique (rotation atomique).
 * Révocation par couple client OAuth / client final : date de révocation (microsecondes) enregistrée AVANT la
 * révocation des jetons, comparée à l'émission (un jeton né d'un parent antérieur à la révocation est refusé).
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeOAuth
{
    const ACCESS_PREFIX = 'ucpat_';
    const REFRESH_PREFIX = 'ucprt_';
    const ACCESS_TTL = 3600;
    const REFRESH_TTL = 2592000; // 30 jours
    const CODE_TTL = 300;

    const SCOPE_CHECKOUT = 'dev.ucp.shopping.checkout:manage';
    const SCOPE_ORDER = 'dev.ucp.shopping.order:read';

    public static function scopes()
    {
        return [
            self::SCOPE_CHECKOUT => UcpBridgeI18n::shop('Create and complete orders on your behalf, with your saved address'),
            self::SCOPE_ORDER => UcpBridgeI18n::shop('View the orders placed by this assistant'),
        ];
    }

    public static function installDb()
    {
        $p = _DB_PREFIX_;
        $e = _MYSQL_ENGINE_;

        return Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}ucpbridge_oauth_code` (
                `code_hash` CHAR(64) NOT NULL,
                `client_id` VARCHAR(128) NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL,
                `redirect_uri` VARCHAR(512) NOT NULL,
                `code_challenge` VARCHAR(128) NOT NULL,
                `scope` VARCHAR(512) NOT NULL,
                `used` TINYINT(1) NOT NULL DEFAULT 0,
                `issued_us` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`code_hash`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4")
            && Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}ucpbridge_oauth_token` (
                `token_hash` CHAR(64) NOT NULL,
                `kind` VARCHAR(8) NOT NULL,
                `client_id` VARCHAR(128) NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL,
                `scope` VARCHAR(512) NOT NULL,
                `revoked` TINYINT(1) NOT NULL DEFAULT 0,
                `issued_us` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `expires_at` DATETIME NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`token_hash`),
                KEY `id_customer` (`id_customer`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4")
            && Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}ucpbridge_oauth_revocation` (
                `client_id` VARCHAR(128) NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL,
                `revoked_us` BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (`client_id`, `id_customer`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4");
    }

    public static function uninstallDb()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ucpbridge_oauth_code`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ucpbridge_oauth_token`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ucpbridge_oauth_revocation`');
    }

    /** Horloge en microsecondes (entier) : ordre d'émission et de révocation. */
    private static function now()
    {
        return (int) round(microtime(true) * 1000000);
    }

    /** Date de la dernière révocation du couple client OAuth / client final (0 = jamais). */
    public static function revokedAt($clientId, $idCustomer)
    {
        return (int) Db::getInstance()->getValue('SELECT revoked_us FROM `' . _DB_PREFIX_ . 'ucpbridge_oauth_revocation`
            WHERE client_id = \'' . pSQL($clientId) . '\' AND id_customer = ' . (int) $idCustomer, false);
    }

    private static function h($v)
    {
        return hash('sha256', (string) $v);
    }

    /* ------------------------------ Clients ------------------------------ */

    /** Clients : JSON en configuration {client_id: {name, secret_sha256, redirect_uris[]}}. */
    public static function clients()
    {
        $c = json_decode((string) Configuration::get('UCPBRIDGE_OAUTH_CLIENTS'), true);

        return is_array($c) ? $c : [];
    }

    public static function client($id)
    {
        $c = self::clients();

        return isset($c[$id]) ? $c[$id] + ['client_id' => $id] : null;
    }

    /** Enregistre (ou remplace) un client ; renvoie le secret en clair, affiché une seule fois. */
    public static function registerClient($id, $name, array $redirectUris, $secret = null)
    {
        $secret = $secret ?: bin2hex(random_bytes(24));
        $c = self::clients();
        $c[$id] = ['name' => $name, 'secret_sha256' => self::h($secret), 'redirect_uris' => array_values($redirectUris)];
        Configuration::updateValue('UCPBRIDGE_OAUTH_CLIENTS', json_encode($c, JSON_UNESCAPED_SLASHES));

        return $secret;
    }

    /** Authentification du client au point de jeton : Basic (préféré) ou corps de requête. */
    public static function authenticateClient($basicUser, $basicPass, $postId, $postSecret)
    {
        $id = '' !== (string) $basicUser ? rawurldecode((string) $basicUser) : (string) $postId;
        $secret = '' !== (string) $basicUser ? rawurldecode((string) $basicPass) : (string) $postSecret;
        $c = self::client($id);
        if (!$c || '' === $secret || !hash_equals((string) $c['secret_sha256'], self::h($secret))) {
            return null;
        }

        return $c;
    }

    public static function redirectAllowed(array $client, $uri)
    {
        return in_array((string) $uri, (array) $client['redirect_uris'], true); // correspondance exacte
    }

    /** Ne garde que les scopes connus ; défaut : tous (écran de consentement groupé, comme Google le demande). */
    public static function filterScope($scope)
    {
        $asked = array_filter(preg_split('/\s+/', trim((string) $scope)));
        $known = array_keys(self::scopes());
        $ok = $asked ? array_values(array_intersect($asked, $known)) : $known;

        return implode(' ', $ok);
    }

    /* ------------------------------ Codes ------------------------------ */

    public static function issueCode($clientId, $idCustomer, $redirectUri, $challenge, $scope)
    {
        $code = bin2hex(random_bytes(24));
        Db::getInstance()->insert('ucpbridge_oauth_code', [
            'code_hash' => self::h($code),
            'client_id' => pSQL($clientId),
            'id_customer' => (int) $idCustomer,
            'redirect_uri' => pSQL($redirectUri),
            'code_challenge' => pSQL($challenge),
            'scope' => pSQL($scope),
            'issued_us' => self::now(),
            'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_TTL),
        ]);

        return $code;
    }

    /** Échange du code : usage unique, client et redirect_uri identiques, PKCE S256 vérifié. */
    public static function redeemCode(array $client, $code, $redirectUri, $verifier)
    {
        $hash = self::h($code);
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ucpbridge_oauth_code` WHERE code_hash = \'' . pSQL($hash) . '\'');
        if (!$row) {
            return ['invalid_grant', UcpBridgeI18n::api('Unknown code')];
        }
        // Client vérifié AVANT toute consommation : un autre client enregistré qui détient le code (fuite par un journal,
        // un en-tête Referer...) ne peut ni le brûler ni déclencher la révocation des jetons de son propriétaire.
        // Même réponse qu'un code inconnu : rien n'indique à ce client que le code existe.
        if (!hash_equals((string) $row['client_id'], (string) $client['client_id'])) {
            return ['invalid_grant', UcpBridgeI18n::api('Unknown code')];
        }
        // Consommation atomique : un code rejoué ne passe jamais deux fois.
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'ucpbridge_oauth_code` SET used = 1 WHERE code_hash = \'' . pSQL($hash) . '\' AND used = 0');
        if (1 !== (int) Db::getInstance()->Affected_Rows()) {
            // RFC 6749 §4.1.2 : code rejoué, on révoque les jetons déjà émis pour ce client et ce client final.
            self::revokeAllFor($row['client_id'], (int) $row['id_customer']);

            return ['invalid_grant', UcpBridgeI18n::api('Code already used')];
        }
        if (strtotime($row['expires_at']) < time()) {
            return ['invalid_grant', UcpBridgeI18n::api('Code expired')];
        }
        if ($row['redirect_uri'] !== (string) $redirectUri) {
            return ['invalid_grant', UcpBridgeI18n::api('redirect_uri different from the authorization request')];
        }
        if (!UcpBridgePkce::isValidVerifier($verifier)) {
            return ['invalid_grant', UcpBridgeI18n::api('code_verifier missing or malformed (PKCE required)')];
        }
        if (!UcpBridgePkce::verify($verifier, $row['code_challenge'])) {
            return ['invalid_grant', UcpBridgeI18n::api('code_verifier does not match code_challenge')];
        }

        return self::issueTokens($client['client_id'], (int) $row['id_customer'], $row['scope'], (int) $row['issued_us']);
    }

    /* ------------------------------ Jetons ------------------------------ */

    private static function storeToken($prefix, $kind, $clientId, $idCustomer, $scope, $ttl)
    {
        $tok = $prefix . bin2hex(random_bytes(32));
        Db::getInstance()->insert('ucpbridge_oauth_token', [
            'token_hash' => self::h($tok),
            'kind' => $kind,
            'client_id' => pSQL($clientId),
            'id_customer' => (int) $idCustomer,
            'scope' => pSQL($scope),
            'issued_us' => self::now(),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return $tok;
    }

    /**
     * @param int|null $since émission du parent (code ou jeton de rafraîchissement) : contrôle de révocation APRÈS
     *                        l'insertion des nouveaux jetons. Révocation enregistrée avant ce contrôle : jetons annulés ;
     *                        après : ses UPDATE passent après l'insertion et les révoquent. Aucun survivant possible.
     */
    public static function issueTokens($clientId, $idCustomer, $scope, $since = null)
    {
        $access = self::storeToken(self::ACCESS_PREFIX, 'access', $clientId, $idCustomer, $scope, self::ACCESS_TTL);
        $refresh = self::storeToken(self::REFRESH_PREFIX, 'refresh', $clientId, $idCustomer, $scope, self::REFRESH_TTL);
        if (null !== $since && self::revokedAt($clientId, $idCustomer) >= (int) $since) {
            self::revokeHash(self::h($access));
            self::revokeHash(self::h($refresh));

            return ['invalid_grant', UcpBridgeI18n::api('Access revoked by the customer or the merchant')];
        }

        return [null, [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => $scope,
        ]];
    }

    private static function find($token, $kind)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ucpbridge_oauth_token`
            WHERE token_hash = \'' . pSQL(self::h($token)) . '\' AND kind = \'' . pSQL($kind) . '\'');
        if (!$row || (int) $row['revoked'] || strtotime($row['expires_at']) < time()) {
            return null;
        }
        // Jeton émis avant (ou au moment de) la dernière révocation du couple : refusé même s'il a échappé à l'UPDATE.
        if ((int) $row['issued_us'] <= self::revokedAt($row['client_id'], (int) $row['id_customer'])) {
            return null;
        }
        $c = new Customer((int) $row['id_customer']);
        if (!Validate::isLoadedObject($c) || !$c->active || $c->is_guest) {
            return null;
        }

        return $row;
    }

    /** Jeton d'accès valide : [id_customer, client_id, scopes[]] ou null. */
    public static function verifyAccess($token)
    {
        if (0 !== strpos((string) $token, self::ACCESS_PREFIX)) {
            return null;
        }
        $row = self::find($token, 'access');

        return $row ? ['id_customer' => (int) $row['id_customer'], 'client_id' => $row['client_id'],
            'scopes' => array_filter(explode(' ', $row['scope']))] : null;
    }

    /**
     * Rafraîchissement : rotation atomique (UPDATE conditionnel, une ligne modifiée exigée). Sur N rafraîchissements
     * simultanés du même jeton, un seul obtient une nouvelle paire ; les autres reçoivent invalid_grant.
     */
    public static function refresh(array $client, $refreshToken)
    {
        $row = self::find($refreshToken, 'refresh');
        if (!$row || $row['client_id'] !== $client['client_id']) {
            return ['invalid_grant', UcpBridgeI18n::api('Invalid refresh token')];
        }
        $db = Db::getInstance();
        $db->execute('UPDATE `' . _DB_PREFIX_ . 'ucpbridge_oauth_token` SET revoked = 1
            WHERE token_hash = \'' . pSQL($row['token_hash']) . '\' AND kind = \'refresh\' AND revoked = 0');
        if (1 !== (int) $db->Affected_Rows()) {
            return ['invalid_grant', UcpBridgeI18n::api('Refresh token already used or revoked')];
        }

        return self::issueTokens($client['client_id'], (int) $row['id_customer'], $row['scope'], (int) $row['issued_us']);
    }

    private static function revokeHash($hash)
    {
        Db::getInstance()->update('ucpbridge_oauth_token', ['revoked' => 1], 'token_hash = \'' . pSQL($hash) . '\'');
    }

    /** RFC 7009 : révoque le jeton s'il appartient au client authentifié ; répond 200 dans tous les cas. */
    public static function revoke(array $client, $token)
    {
        $hash = self::h($token);
        Db::getInstance()->update('ucpbridge_oauth_token', ['revoked' => 1],
            'token_hash = \'' . pSQL($hash) . '\' AND client_id = \'' . pSQL($client['client_id']) . '\'');
    }

    /** Révoque tous les jetons (accès et rafraîchissement) et codes en attente d'un client OAuth pour un client final. */
    public static function revokeAllFor($clientId, $idCustomer)
    {
        // D'abord la date de révocation (lue par issueTokens et find), ensuite les jetons et les codes.
        $now = self::now();
        Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'ucpbridge_oauth_revocation` (client_id, id_customer, revoked_us)
            VALUES (\'' . pSQL($clientId) . '\', ' . (int) $idCustomer . ', ' . $now . ')
            ON DUPLICATE KEY UPDATE revoked_us = GREATEST(revoked_us, VALUES(revoked_us))');
        Db::getInstance()->update('ucpbridge_oauth_token', ['revoked' => 1],
            'client_id = \'' . pSQL($clientId) . '\' AND id_customer = ' . (int) $idCustomer);
        Db::getInstance()->update('ucpbridge_oauth_code', ['used' => 1],
            'client_id = \'' . pSQL($clientId) . '\' AND id_customer = ' . (int) $idCustomer);
    }

    /**
     * Accès actifs (au moins un jeton non révoqué et non expiré), par client OAuth et client final.
     *
     * @param int $idCustomer 0 = tous les clients (back-office)
     */
    public static function grants($idCustomer = 0)
    {
        $rows = Db::getInstance()->executeS('SELECT t.client_id, t.id_customer, c.email, MIN(t.date_add) AS first_grant,
                MAX(t.expires_at) AS expires_at, GROUP_CONCAT(DISTINCT t.scope SEPARATOR \' \') AS scopes
            FROM `' . _DB_PREFIX_ . 'ucpbridge_oauth_token` t
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = t.id_customer
            WHERE t.revoked = 0 AND t.expires_at > \'' . pSQL(date('Y-m-d H:i:s')) . '\''
            . ($idCustomer ? ' AND t.id_customer = ' . (int) $idCustomer : '') . '
            GROUP BY t.client_id, t.id_customer, c.email ORDER BY first_grant DESC') ?: [];
        $clients = self::clients();
        foreach ($rows as &$r) {
            $r['client_name'] = (string) ($clients[$r['client_id']]['name'] ?? $r['client_id']);
            $r['scopes'] = array_values(array_unique(array_filter(explode(' ', (string) $r['scopes']))));
        }

        return $rows;
    }

    /** Métadonnées RFC 8414. */
    public static function metadata(Link $link)
    {
        $base = rtrim($link->getBaseLink(), '/');

        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/ucp/oauth/authorize',
            'token_endpoint' => $base . '/ucp/oauth/token',
            'revocation_endpoint' => $base . '/ucp/oauth/revoke',
            'scopes_supported' => array_keys(self::scopes()),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'revocation_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'service_documentation' => 'https://ucp.dev/2026-04-08/specification/identity-linking/',
        ];
    }
}
