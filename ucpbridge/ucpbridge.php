<?php
/**
 * ucpbridge : passerelle UCP (Universal Commerce Protocol 2026-04-08) pour PrestaShop.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 *
 * Le module étend PaymentModule uniquement pour pouvoir appeler validateOrder()
 * depuis la caisse native UCP. Il n'expose aucun moyen de paiement au tunnel
 * de commande classique (pas de hook paymentOptions).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/UcpBridgeItemRef.php';
require_once __DIR__ . '/classes/UcpBridgeTotals.php';
require_once __DIR__ . '/classes/UcpBridgeSigner.php';
require_once __DIR__ . '/classes/UcpBridgePkce.php';
require_once __DIR__ . '/classes/UcpBridgeConsent.php';
require_once __DIR__ . '/classes/UcpBridgeLegal.php';
require_once __DIR__ . '/classes/UcpBridgeCatalog.php';
require_once __DIR__ . '/classes/UcpBridgeCart.php';
require_once __DIR__ . '/classes/UcpBridgeIdempotency.php';
require_once __DIR__ . '/classes/UcpBridgeApiKeys.php';
require_once __DIR__ . '/classes/UcpBridgeSchema.php';
require_once __DIR__ . '/classes/UcpBridgeDiscount.php';
require_once __DIR__ . '/classes/UcpBridgeOAuth.php';
require_once __DIR__ . '/classes/UcpBridgeProof.php';
require_once __DIR__ . '/classes/UcpBridgeExport.php';
require_once __DIR__ . '/classes/UcpBridgeRetention.php';
require_once __DIR__ . '/classes/UcpBridgePurge.php';
require_once __DIR__ . '/classes/UcpBridgePage.php';
require_once __DIR__ . '/classes/UcpBridgeMcp.php';
require_once __DIR__ . '/classes/payment/UcpBridgePaymentProvider.php';
require_once __DIR__ . '/classes/payment/UcpBridgeMockProvider.php';
require_once __DIR__ . '/classes/payment/UcpBridgeStripeProvider.php';

class Ucpbridge extends PaymentModule
{
    const UCP_VERSION = '2026-04-08';
    /** Nom affiché (back-office, pages du module, MCP). Le nom technique reste « ucpbridge ». */
    const DISPLAY_NAME = 'UCP Bridge pour PrestaShop';
    /** Délai par défaut (minutes) au-delà duquel une session réservée est considérée comme bloquée. */
    const RECONCILE_MINUTES = 15;
    const HTACCESS_BEGIN = '# ucpbridge:begin';
    const HTACCESS_END = '# ucpbridge:end';
    /** Hook déclaré par le module : les modules de paiement tiers y inscrivent leur adaptateur. */
    const HOOK_PROVIDERS = 'actionUcpBridgeRegisterPaymentProviders';
    /** Pages CMS des liens légaux (configuration type => clé). */
    const CMS_KEYS = [
        'terms_of_service' => 'UCPBRIDGE_CMS_TERMS',
        'privacy_policy' => 'UCPBRIDGE_CMS_PRIVACY',
        'refund_policy' => 'UCPBRIDGE_CMS_REFUND',
        'shipping_policy' => 'UCPBRIDGE_CMS_SHIPPING',
    ];
    const CONFIG_KEYS = ['UCPBRIDGE_API_KEY', 'UCPBRIDGE_API_KEYS', 'UCPBRIDGE_LINK_SECRET', 'UCPBRIDGE_MOCK_SECRET', 'UCPBRIDGE_STRIPE_KEY', 'UCPBRIDGE_STRIPE_PK',
        'UCPBRIDGE_STRIPE_API_BASE', 'UCPBRIDGE_GPAY_MERCHANT_ID', 'UCPBRIDGE_OAUTH_CLIENTS', 'UCPBRIDGE_TERMS_REVIEW',
        'UCPBRIDGE_CMS_TERMS', 'UCPBRIDGE_CMS_PRIVACY', 'UCPBRIDGE_CMS_REFUND', 'UCPBRIDGE_CMS_SHIPPING', 'UCPBRIDGE_UNINSTALL_PURGE',
        'UCPBRIDGE_RETENTION_SESSIONS', 'UCPBRIDGE_RETENTION_GUESTS', 'UCPBRIDGE_RETENTION_OAUTH', 'UCPBRIDGE_RETENTION_PAYMENTS',
        'UCPBRIDGE_RETENTION_PROOFS'];
    const HOOKS = ['moduleRoutes', 'actionHtaccessCreate', 'displayAdminOrderMain', 'actionEmailAddAfterContent',
        'actionGetExtraMailTemplateVars', 'displayCustomerAccount'];
    /** Durée de validité du lien de rétractation envoyé par e-mail (le droit court 14 jours après la réception). */
    const WITHDRAW_TTL = 10368000; // 120 jours

    public function __construct()
    {
        $this->name = 'ucpbridge';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.3';
        $this->author = 'Friends of Presta (Christophe Vidal)';
        $this->need_instance = 0;
        $this->bootstrap = true;
        // Versions éprouvées par la suite de tests (images officielles) : PrestaShop 8.0, 8.1 et 8.2 sous PHP 8.1,
        // 9.0, 9.1 et 9.2 sous le PHP de leur image. PHP 8.1 minimum (voir install()).
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = self::DISPLAY_NAME;
        $this->description = 'Universal Commerce Protocol : profil, panier transférable, caisse native, déclinaisons, CGV et preuve, MCP.';
    }

    /** PHP minimum du module : PrestaShop 8 accepte encore PHP 7.2 à 8.0, que le module ne prend pas en charge. */
    const PHP_MIN = '8.1.0';

    public function install()
    {
        if (version_compare(PHP_VERSION, self::PHP_MIN, '<')) {
            $this->_errors[] = self::DISPLAY_NAME . ' exige PHP ' . self::PHP_MIN . ' ou plus récent (PHP ' . PHP_VERSION
                . ' sur ce serveur). Mettre PHP à jour avant d\'installer le module.';

            return false;
        }

        return parent::install()
            && $this->registerHook(self::HOOKS)
            && $this->installProviderHook()
            && $this->installDb()
            && UcpBridgeOAuth::installDb()
            && UcpBridgeProof::installDb()
            // Tables de preuve conservées par une désinstallation antérieure : complétées si elles datent d'une version
            // plus ancienne, sans toucher aux lignes.
            && UcpBridgeSchema::addMissingColumns(self::ADDED_COLUMNS)
            && $this->installConfig()
            && $this->installHtaccess();
    }

    /**
     * Colonnes ajoutées aux tables après la première publication (0.1.2) : [table sans préfixe => [colonne => définition]].
     * Toute colonne ajoutée à un CREATE TABLE doit aussi figurer ici, sinon une boutique mise à jour ne l'aura pas
     * (test d'intégration tests/test_11_mise_a_jour.py : schéma mis à jour comparé à celui d'une installation fraîche).
     * Aucune pour la 0.1.3 : les corrections #1 à #4 n'ajoutent que des clés dans le JSON `payload` des sessions
     * (charge_attempt, charge_unknown), lues avec une valeur par défaut.
     */
    const ADDED_COLUMNS = [];

    /**
     * Mise à jour en place, sans perte (appelée par chaque script upgrade/upgrade-X.Y.Z.php) : tables manquantes
     * créées, colonnes ajoutées, hooks enregistrés, clés de configuration manquantes créées avec leur valeur par
     * défaut, règles de réécriture réinsérées. Les lignes et les valeurs déjà configurées ne sont jamais modifiées.
     * Rejouable : un second passage ne change rien.
     */
    public function upgradeInPlace()
    {
        // Clé unique de la 0.1.2 (UCPBRIDGE_API_KEY) migrée vers la plateforme « default » AVANT de créer les clés
        // manquantes : sinon UCPBRIDGE_API_KEYS naîtrait avec une clé neuve et la plateforme en place perdrait l'accès.
        // L'ancienne clé n'est supprimée qu'une fois la nouvelle enregistrée ; sinon la mise à jour échoue.
        if (!UcpBridgeApiKeys::migrateLegacy()) {
            return false;
        }

        return $this->installProviderHook()
            && $this->registerHook(self::HOOKS)
            && $this->installDb()
            && UcpBridgeOAuth::installDb()
            && UcpBridgeProof::installDb()
            && UcpBridgeSchema::addMissingColumns(self::ADDED_COLUMNS)
            // Invités créés par une version antérieure (sessions encore en base) : repris pour la purge RGPD.
            && UcpBridgePurge::backfillGuests()
            && UcpBridgeSchema::addMissingConfig($this->configDefaults())
            && $this->installHtaccess();
    }

    public function uninstall()
    {
        $this->removeHtaccess();
        // Choix lu AVANT la suppression de la configuration : sans case cochée, les preuves sont conservées.
        $dropEvidence = (bool) Configuration::get('UCPBRIDGE_UNINSTALL_PURGE');
        foreach (self::CONFIG_KEYS as $k) {
            Configuration::deleteByName($k);
        }
        UcpBridgeOAuth::uninstallDb();
        UcpBridgeProof::uninstallDb($dropEvidence);
        if ($dropEvidence) {
            // Liste des invités créés par le module : gardée avec les preuves, pour que la purge les retrouve après une
            // réinstallation (identifiants seulement, aucune donnée personnelle).
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ucpbridge_customer`');
        }
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ucpbridge_session`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ucpbridge_idempotency`');
        $ok = parent::uninstall();
        // Hook d'extension créé par le module : supprimé s'il ne sert plus à aucun autre module.
        $idHook = (int) Hook::getIdByName(self::HOOK_PROVIDERS);
        if ($idHook && !Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'hook_module` WHERE id_hook = ' . $idHook)) {
            (new Hook($idHook))->delete();
        }

        return $ok;
    }

    private function installDb()
    {
        $p = _DB_PREFIX_;
        $e = _MYSQL_ENGINE_;
        $ok = Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}ucpbridge_session` (
            `id_session` VARCHAR(40) NOT NULL,
            `kind` VARCHAR(10) NOT NULL,
            `id_cart` INT UNSIGNED NOT NULL DEFAULT 0,
            `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(32) NOT NULL,
            `owner` VARCHAR(200) NOT NULL DEFAULT '',
            `order_ref` VARCHAR(40) NULL,
            `payload` MEDIUMTEXT NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_session`),
            KEY `id_cart` (`id_cart`),
            KEY `id_order` (`id_order`),
            UNIQUE KEY `order_ref` (`order_ref`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4");

        // Clients invités créés par le module (purge RGPD des invités sans commande).
        $ok = $ok && Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}ucpbridge_customer` (
            `id_customer` INT UNSIGNED NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_customer`),
            KEY `date_add` (`date_add`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4");

        return $ok && Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}ucpbridge_idempotency` (
            `idem_key` VARCHAR(255) NOT NULL,
            `request_hash` CHAR(64) NOT NULL,
            `http_code` SMALLINT NOT NULL DEFAULT 0,
            `response` MEDIUMTEXT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`idem_key`),
            KEY `date_add` (`date_add`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Configuration d'une installation : valeur de chaque clé, ou fonction anonyme qui la calcule (secrets aléatoires,
     * pages légales devinées). L'installation écrit tout ; la mise à jour ne crée que les clés absentes.
     */
    private function configDefaults()
    {
        // Clé API de la plateforme par défaut (Bearer) et secret HMAC des liens signés : générés à l'installation.
        // Chaque plateforme supplémentaire reçoit sa propre clé (configuration du module).
        // Le paiement de test reste désactivé tant que UCPBRIDGE_MOCK_SECRET est vide.
        $defaults = [
            UcpBridgeApiKeys::CONFIG => function () {
                return UcpBridgeApiKeys::encode(UcpBridgeApiKeys::initial(date('Y-m-d H:i:s')));
            },
            'UCPBRIDGE_LINK_SECRET' => function () {
                return bin2hex(random_bytes(32));
            },
            'UCPBRIDGE_MOCK_SECRET' => '',
            'UCPBRIDGE_STRIPE_KEY' => '',
            'UCPBRIDGE_STRIPE_PK' => '',
            'UCPBRIDGE_STRIPE_API_BASE' => UcpBridgeStripeProvider::DEFAULT_BASE,
            'UCPBRIDGE_GPAY_MERCHANT_ID' => '',
            'UCPBRIDGE_OAUTH_CLIENTS' => '{}',
            'UCPBRIDGE_TERMS_REVIEW' => 0,
            // 1 = supprimer journal de preuve, rétractations et journal des paiements à la désinstallation (0 = conserver).
            'UCPBRIDGE_UNINSTALL_PURGE' => 0,
        ] + UcpBridgeRetention::defaults(); // durées de conservation (jours) par catégorie
        // Pages légales devinées depuis la boutique (CGV : la page des conditions du tunnel de commande).
        foreach (self::CMS_KEYS as $type => $key) {
            $defaults[$key] = function () use ($type) {
                $map = UcpBridgeLegal::defaultMapping($this->legalPages(), (int) Configuration::get('PS_CONDITIONS_CMS_ID'));

                return (int) $map[$type];
            };
        }

        return $defaults;
    }

    private function installConfig()
    {
        // Clé unique des versions précédentes : remplacée par la plateforme par défaut de UCPBRIDGE_API_KEYS.
        Configuration::deleteByName(UcpBridgeApiKeys::LEGACY);
        $ok = true;
        foreach ($this->configDefaults() as $key => $v) {
            $ok = $ok && Configuration::updateValue($key, $v instanceof Closure ? $v() : $v);
        }

        return $ok;
    }

    /** Crée le hook d'extension s'il n'existe pas (aucun module ne s'y greffe par défaut). */
    private function installProviderHook()
    {
        if (Hook::getIdByName(self::HOOK_PROVIDERS)) {
            return true;
        }
        $h = new Hook();
        $h->name = self::HOOK_PROVIDERS;
        $h->title = 'UCP Bridge pour PrestaShop : inscription des adaptateurs de paiement';
        $h->description = 'Paramètre registry (UcpBridgePaymentRegistry) : appeler $params[\'registry\']->register(new MonAdaptateur())';
        $h->position = 0;

        return (bool) $h->add();
    }

    /* ---------- Réécriture d'URL : /.well-known/ucp et /ucp/v1/* ---------- */

    private function htaccessPath()
    {
        return _PS_ROOT_DIR_ . '/.htaccess';
    }

    private function htaccessBlock()
    {
        $base = rtrim(__PS_BASE_URI__, '/') . '/';
        $target = 'index.php?fc=module&module=ucpbridge&controller=api';
        $oauth = 'index.php?fc=module&module=ucpbridge&controller=oauth';

        return self::HTACCESS_BEGIN . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine on\n"
            . "RewriteBase " . $base . "\n"
            . "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
            . "RewriteRule ^\\.well-known/ucp/?$ " . $target . "&ucp_path=profile [QSA,L]\n"
            . "RewriteRule ^ucp/v1/(.*)$ " . $target . "&ucp_path=$1 [QSA,L]\n"
            . "RewriteRule ^\\.well-known/oauth-authorization-server/?$ " . $oauth . "&ucp_oauth=metadata [QSA,L]\n"
            . "RewriteRule ^ucp/oauth/(authorize|token|revoke)$ " . $oauth . "&ucp_oauth=$1 [QSA,L]\n"
            . "</IfModule>\n"
            . self::HTACCESS_END . "\n";
    }

    public function installHtaccess()
    {
        $path = $this->htaccessPath();
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $content = $this->stripBlock($content);

        // Nos règles passent AVANT le bloc PrestaShop pour être évaluées en premier.
        return false !== @file_put_contents($path, $this->htaccessBlock() . $content);
    }

    private function removeHtaccess()
    {
        $path = $this->htaccessPath();
        if (is_file($path)) {
            @file_put_contents($path, $this->stripBlock((string) file_get_contents($path)));
        }
    }

    private function stripBlock($content)
    {
        $start = strpos($content, self::HTACCESS_BEGIN);
        $end = strpos($content, self::HTACCESS_END);
        if (false === $start || false === $end) {
            return $content;
        }

        return substr($content, 0, $start) . ltrim(substr($content, $end + strlen(self::HTACCESS_END)), "\n");
    }

    /** PrestaShop régénère le .htaccess (URL conviviales, SEO) : on se réinsère. */
    public function hookActionHtaccessCreate()
    {
        $this->installHtaccess();
    }

    /** Route conviviale de secours pour l'API (le routeur ne sert pas /.well-known/). */
    public function hookModuleRoutes()
    {
        return [
            'module-ucpbridge-api' => [
                'controller' => 'api',
                'rule' => 'ucp/v1/{ucp_path}',
                'keywords' => ['ucp_path' => ['regexp' => '[/a-zA-Z0-9_\-]+', 'param' => 'ucp_path']],
                'params' => ['fc' => 'module', 'module' => 'ucpbridge', 'controller' => 'api'],
            ],
        ];
    }

    /* ---------- Lien de reprise signé (continue_url) ---------- */

    /** Lien signé vers un contrôleur du module ; l'usage (handoff, review, withdraw) fait partie de la signature. */
    public function signedLink($controller, $purpose, array $params, $ttl)
    {
        return $this->context->link->getModuleLink('ucpbridge', $controller,
            UcpBridgeSigner::signedParams((string) Configuration::get('UCPBRIDGE_LINK_SECRET'), $purpose, $params, $ttl));
    }

    /** Vérifie la signature des paramètres reçus (noms attendus + exp + sig). */
    public function checkSigned($purpose, array $names)
    {
        $params = [];
        foreach (array_merge($names, ['exp', 'sig']) as $n) {
            $params[$n] = (string) Tools::getValue($n);
        }

        return UcpBridgeSigner::verify((string) Configuration::get('UCPBRIDGE_LINK_SECRET'), $purpose, $params);
    }

    public function buildContinueUrl($idCart, $ttl = 86400)
    {
        return $this->signedLink('handoff', 'handoff', ['cart' => (int) $idCart], $ttl);
    }

    /* ---------- Liens légaux, CGV, rétractation ---------- */

    /** Pages CMS de la boutique dans la langue du contexte : id => [title, rewrite, url, active]. */
    public function legalPages()
    {
        $ctx = Context::getContext();
        $idLang = (int) ($ctx->language->id ?? Configuration::get('PS_LANG_DEFAULT'));
        $idShop = (int) ($ctx->shop->id ?? Configuration::get('PS_SHOP_DEFAULT'));
        $rows = Db::getInstance()->executeS('SELECT c.id_cms, c.active, l.meta_title, l.link_rewrite FROM `' . _DB_PREFIX_ . 'cms` c
            INNER JOIN `' . _DB_PREFIX_ . 'cms_lang` l ON l.id_cms = c.id_cms AND l.id_lang = ' . $idLang . ' AND l.id_shop = ' . $idShop . '
            ORDER BY c.position, c.id_cms') ?: [];
        $pages = [];
        foreach ($rows as $r) {
            $pages[(int) $r['id_cms']] = [
                'title' => (string) $r['meta_title'],
                'rewrite' => (string) $r['link_rewrite'],
                'url' => $ctx->link ? $ctx->link->getCMSLink((int) $r['id_cms'], $r['link_rewrite'], null, $idLang) : '',
                'active' => (bool) $r['active'],
            ];
        }

        return $pages;
    }

    public function legalMap()
    {
        $map = [];
        foreach (self::CMS_KEYS as $type => $key) {
            $map[$type] = (int) Configuration::get($key);
        }

        return $map;
    }

    /** links UCP (terms_of_service, privacy_policy, refund_policy, shipping_policy) avec titre en langue de la boutique. */
    public function legalLinks()
    {
        return UcpBridgeLegal::links($this->legalMap(), $this->legalPages());
    }

    /** Mention de rétractation pour un pays de livraison (pays de la boutique à défaut). */
    public function withdrawalDisclosure($iso)
    {
        if (!$iso) {
            $iso = Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        }
        $url = '';
        foreach ($this->legalLinks() as $l) {
            if ('refund_policy' === $l['type'] || ('' === $url && 'terms_of_service' === $l['type'])) {
                $url = $l['url'];
            }
        }

        return UcpBridgeLegal::withdrawalDisclosure($iso, $url);
    }

    /** CGV présentées : id, titre, contenu HTML et version (empreinte du contenu). */
    public function termsInfo()
    {
        $id = (int) Configuration::get('UCPBRIDGE_CMS_TERMS');
        $cms = new CMS($id, (int) Context::getContext()->language->id);
        $content = Validate::isLoadedObject($cms) ? (string) $cms->content : '';

        return [
            'id' => $id,
            'title' => Validate::isLoadedObject($cms) ? (string) $cms->meta_title : '',
            'content' => $content,
            'url' => $id ? Context::getContext()->link->getCMSLink($id) : '',
            'version' => UcpBridgeLegal::termsVersion($id, '', $content),
        ];
    }

    public function termsReviewRequired()
    {
        return (bool) Configuration::get('UCPBRIDGE_TERMS_REVIEW');
    }

    public function termsReviewUrl($sessionId, $ttl = 86400)
    {
        return $this->signedLink('terms', 'terms', ['session' => (string) $sessionId], max(60, (int) $ttl));
    }

    public function withdrawalUrl($idOrder)
    {
        return $this->signedLink('withdraw', 'withdraw', ['order' => (int) $idOrder], self::WITHDRAW_TTL);
    }

    /** Jeton anti-CSRF des formulaires des pages du module, lié au cookie du visiteur. */
    public function formToken(Cookie $cookie, $purpose)
    {
        if (empty($cookie->ucpbridge_csrf)) {
            $cookie->ucpbridge_csrf = bin2hex(random_bytes(16));
            $cookie->write();
        }

        return hash_hmac('sha256', (string) $purpose, $cookie->ucpbridge_csrf . (string) Configuration::get('UCPBRIDGE_LINK_SECRET'));
    }

    public function checkFormToken(Cookie $cookie, $purpose, $token)
    {
        return !empty($cookie->ucpbridge_csrf) && hash_equals($this->formToken($cookie, $purpose), (string) $token);
    }

    /* ---------- E-mail de confirmation : CGV et lien de rétractation ---------- */

    /** Ajoute les emplacements du bloc légal au modèle order_conf (HTML et texte). */
    public function hookActionEmailAddAfterContent($params)
    {
        if ('order_conf' !== ($params['template'] ?? '')) {
            return;
        }
        $html = (string) $params['template_html'];
        $pos = stripos($html, '</body>');
        $params['template_html'] = false === $pos ? $html . '{ucpbridge_legal_html}' : substr($html, 0, $pos) . '{ucpbridge_legal_html}' . substr($html, $pos);
        $params['template_txt'] = (string) $params['template_txt'] . "\n{ucpbridge_legal_txt}\n";
    }

    /** Remplit le bloc légal pour les commandes passées via UCP, le vide pour les autres. */
    public function hookActionGetExtraMailTemplateVars($params)
    {
        if ('order_conf' !== ($params['template'] ?? '')) {
            return;
        }
        $params['extra_template_vars']['{ucpbridge_legal_html}'] = '';
        $params['extra_template_vars']['{ucpbridge_legal_txt}'] = '';
        $ref = trim(strip_tags((string) ($params['template_vars']['{order_name}'] ?? '')));
        if ('' === $ref) {
            return;
        }
        $idOrder = (int) Db::getInstance()->getValue('SELECT id_order FROM `' . _DB_PREFIX_ . 'orders` WHERE reference = \'' . pSQL($ref) . '\'
            AND module = \'' . pSQL($this->name) . '\' ORDER BY id_order DESC');
        if (!$idOrder) {
            return;
        }
        $e = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $links = $this->legalLinks();
        $withdraw = $this->withdrawalUrl($idOrder);
        $items = '';
        $txt = "Informations légales\n";
        foreach (array_unique(array_column($links, 'title', 'url')) as $url => $title) {
            $l = ['url' => $url, 'title' => $title];
            $items .= '<li><a href="' . $e($l['url']) . '">' . $e($l['title']) . '</a></li>';
            $txt .= '- ' . $l['title'] . ' : ' . $l['url'] . "\n";
        }
        $params['extra_template_vars']['{ucpbridge_legal_html}'] = '<table width="100%" style="max-width:600px;margin:16px auto;font-family:Arial,sans-serif;font-size:14px;color:#333">'
            . '<tr><td style="padding:16px;border-top:1px solid #ddd"><p style="font-weight:bold;margin:0 0 8px">Informations légales</p>'
            . '<ul style="margin:0 0 12px;padding-left:18px">' . $items . '</ul>'
            . '<p style="margin:0 0 8px">Droit de rétractation : vous disposez de 14 jours à compter de la réception de votre commande '
            . 'pour vous rétracter, sans avoir à vous justifier.</p>'
            . '<p style="margin:0"><a href="' . $e($withdraw) . '" style="display:inline-block;padding:8px 14px;border:1px solid #31249F;'
            . 'border-radius:6px;color:#31249F;text-decoration:none">Se rétracter de cette commande</a></p></td></tr></table>';
        $params['extra_template_vars']['{ucpbridge_legal_txt}'] = $txt
            . "Droit de rétractation : 14 jours à compter de la réception de votre commande.\n"
            . 'Se rétracter de cette commande : ' . $withdraw . "\n";
    }

    /* ---------- Mes accès agents (compte client) ---------- */

    public function accessUrl()
    {
        return $this->context->link->getModuleLink('ucpbridge', 'access');
    }

    /** Lien « Mes accès agents » sur la page Mon compte : liste et révocation des assistants reliés. */
    public function hookDisplayCustomerAccount($params)
    {
        return '<a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" id="ucpbridge-access-link" href="'
            . htmlspecialchars($this->accessUrl(), ENT_QUOTES, 'UTF-8') . '"><span class="link-item">'
            . '<i class="material-icons">link_off</i>Mes accès agents</span></a>';
    }

    /* ---------- Journal de preuve sur la fiche commande (back-office) ---------- */

    public function hookDisplayAdminOrderMain($params)
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        $p = UcpBridgeProof::get($idOrder);
        if (!$p) {
            return '';
        }
        $e = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $euros = function ($c) {
            return number_format(((int) $c) / 100, 2, ',', ' ') . ' €';
        };
        $rows = [];
        $rows[] = ['Date', $p['date_add']];
        $rows[] = ['Agent (UCP-Agent)', $p['agent'] ?: '(non transmis)'];
        $rows[] = ['Appel API qui a finalisé', trim(($p['caller'] ?? '') . ', IP ' . ($p['api_ip'] ?? '') . ', ' . ($p['api_user_agent'] ?? ''), ', ')];
        $rows[] = ['Session de caisse', $p['session_id']];
        $rows[] = ['Version des CGV présentée', $p['terms_version']];
        $acc = $p['terms_acceptance'];
        $rows[] = ['Case CGV cochée sur la page du marchand', $acc
            ? 'le ' . $acc['accepted_at'] . ', version ' . $acc['version'] . ', IP ' . $acc['ip']
                . (isset($p['api_ip']) && $acc['ip'] === $p['api_ip'] ? ' (MÊME IP que l\'appel API de l\'agent)' : '')
                . '. Attention : preuve non nominative, la case peut avoir été cochée par l\'agent lui-même.'
            : 'non demandée (relecture désactivée)'];
        $links = '';
        foreach ((array) $p['links'] as $l) {
            $links .= '<li>' . $e($l['type']) . ' : <a href="' . $e($l['url']) . '" target="_blank" rel="noopener">' . $e($l['title'] ?? $l['url']) . '</a></li>';
        }
        $disc = '';
        foreach ((array) $p['disclosures'] as $d) {
            $disc .= '<li>' . $e($d['code']) . ' : ' . $e($d['content']) . '</li>';
        }
        $tot = '';
        foreach ((array) $p['totals'] as $t) {
            $tot .= '<li>' . $e($t['display_text'] ?? $t['type']) . ' : ' . $e($euros($t['amount'])) . '</li>';
        }
        $cons = [];
        foreach ((array) $p['consent'] as $k => $v) {
            $cons[] = $k . ' : ' . (null === $v ? 'non exprimé' : ($v ? 'oui' : 'non'));
        }
        $rows[] = ['Consentements (buyer_consent)', implode(' ; ', $cons)];
        $w = UcpBridgeProof::withdrawal($idOrder);
        $rows[] = ['Rétractation en ligne', $w ? 'demandée le ' . $w['date_add'] . ' (IP ' . $w['ip'] . ')' . ($w['email_sent'] ? ', accusé de réception envoyé' : '') : 'aucune demande'];
        $html = '<div class="card mt-2" id="ucpbridge-proof"><div class="card-header"><h3 class="card-header-title">Preuve UCP : ce qui a été présenté à l\'acheteur</h3></div>'
            . '<div class="card-body"><table class="table"><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr><th style="width:30%">' . $e($r[0]) . '</th><td>' . $e($r[1]) . '</td></tr>';
        }
        $html .= '<tr><th>Liens légaux</th><td><ul>' . $links . '</ul></td></tr>'
            . '<tr><th>Mentions</th><td><ul>' . $disc . '</ul></td></tr>'
            . '<tr><th>Totaux présentés</th><td><ul>' . $tot . '</ul></td></tr>'
            . '</tbody></table></div></div>';

        return $html;
    }

    /* ---------- Page de configuration ---------- */

    /** Plateformes et clés API (mode invité) : ajout, régénération, suppression. */
    private function processApiKeys()
    {
        $actions = ['submitUcpbridgeApiKeyAdd', 'submitUcpbridgeApiKeyRegen', 'submitUcpbridgeApiKeyDelete'];
        if (!array_filter($actions, [Tools::class, 'isSubmit'])) {
            return '';
        }
        if (!hash_equals($this->configToken(), (string) Tools::getValue('ucp_csrf'))) {
            return $this->displayError('Jeton de sécurité invalide : clés API inchangées.');
        }
        $id = (string) Tools::getValue('api_key_id');
        if (Tools::isSubmit('submitUcpbridgeApiKeyDelete')) {
            UcpBridgeApiKeys::remove($id);

            return $this->displayConfirmation('Clé de la plateforme « ' . $id . ' » supprimée : elle est refusée dès maintenant.');
        }
        $key = Tools::isSubmit('submitUcpbridgeApiKeyAdd')
            ? UcpBridgeApiKeys::add($id, (string) Tools::getValue('api_key_label'))
            : UcpBridgeApiKeys::regenerate($id);
        if (null === $key) {
            return $this->displayError('Identifiant de plateforme invalide ou déjà utilisé (minuscules, chiffres, - et _, 32 caractères au plus).');
        }

        return $this->displayConfirmation('Clé de la plateforme « ' . $id . ' » : ' . $key . ' (à transmettre à cette plateforme seulement)');
    }

    private function apiKeysPanel(callable $esc)
    {
        $csrf = '<input type="hidden" name="ucp_csrf" value="' . $esc($this->configToken()) . '">';
        $rows = '';
        foreach (UcpBridgeApiKeys::all() as $id => $k) {
            $rows .= '<tr><td><code>' . $esc($id) . '</code></td><td>' . $esc($k['label'] ?? '') . '</td><td><code>' . $esc($k['key'] ?? '')
                . '</code></td><td>' . $esc($k['date_add'] ?? '') . '</td><td><form method="post" class="form-inline">' . $csrf
                . '<input type="hidden" name="api_key_id" value="' . $esc($id) . '">'
                . '<button class="btn btn-default" name="submitUcpbridgeApiKeyRegen" value="1">Régénérer</button> '
                . '<button class="btn btn-default" name="submitUcpbridgeApiKeyDelete" value="1" '
                . 'onclick="return confirm(\'Supprimer la clé de cette plateforme ?\')">Supprimer</button></form></td></tr>';
        }

        return '<h4>Plateformes (clés API, en-tête Authorization: Bearer)</h4>'
            . '<p>Une clé par plateforme : chaque plateforme ne voit que ses propres paniers, sessions de caisse et commandes, '
            . 'et ses clés d\'idempotence ne se mélangent pas à celles des autres. Ne jamais remettre la même clé à deux plateformes.</p>'
            . ($rows ? '<table class="table"><thead><tr><th>Identifiant</th><th>Plateforme</th><th>Clé API</th><th>Créée le</th><th></th></tr></thead><tbody>'
                . $rows . '</tbody></table>' : '<p>Aucune clé : le mode invité est fermé.</p>')
            . '<form method="post" class="form-inline">' . $csrf
            . '<input type="text" class="form-control" name="api_key_id" placeholder="identifiant (ex. google)" pattern="[a-z0-9][a-z0-9_-]{0,31}"> '
            . '<input type="text" class="form-control" name="api_key_label" placeholder="nom affiché"> '
            . '<button class="btn btn-default" name="submitUcpbridgeApiKeyAdd" value="1">Ajouter une plateforme</button></form>';
    }

    /** Accès agents actifs (jetons OAuth non révoqués et non expirés), révocables par le marchand. */
    private function grantsPanel(callable $esc)
    {
        $rows = '';
        foreach (UcpBridgeOAuth::grants() as $g) {
            $rows .= '<tr><td>' . $esc($g['client_name']) . ' <code>' . $esc($g['client_id']) . '</code></td><td>' . $esc($g['email'])
                . '</td><td>' . $esc($g['first_grant']) . '</td><td>' . $esc($g['expires_at']) . '</td><td><form method="post">'
                . '<input type="hidden" name="ucp_csrf" value="' . $esc($this->configToken()) . '">'
                . '<input type="hidden" name="revoke_client" value="' . $esc($g['client_id']) . '">'
                . '<input type="hidden" name="revoke_customer" value="' . (int) $g['id_customer'] . '">'
                . '<button class="btn btn-default" name="submitUcpbridgeRevoke" value="1">Révoquer</button></form></td></tr>';
        }

        return '<h4>Accès agents actifs</h4>' . ($rows
            ? '<table class="table"><thead><tr><th>Assistant</th><th>Client</th><th>Relié le</th><th>Valable jusqu\'au</th><th></th></tr></thead><tbody>'
                . $rows . '</tbody></table>'
            : '<p>Aucun assistant relié à un compte client.</p>');
    }

    /** Rapprochement des sessions bloquées (processus interrompu pendant la finalisation). */
    private function reconcilePanel(callable $esc)
    {
        $n = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucpbridge_session` WHERE kind = \'checkout\'
            AND status IN (\'complete_in_progress\', \'reconciling\', \'updating\') AND date_upd < \''
            . pSQL(date('Y-m-d H:i:s', time() - 60 * self::RECONCILE_MINUTES)) . '\'');

        return '<h4>Rapprocher les sessions bloquées</h4><p>Sessions réservées depuis plus de ' . self::RECONCILE_MINUTES . ' minutes : <strong>'
            . $n . '</strong>. Commande trouvée : session terminée ; débit sans commande : remboursement et incident ; '
            . 'débit incertain : incident à vérifier chez le prestataire ; aucun débit : session rendue. '
            . 'À planifier aussi en ligne de commande : <code>php modules/ucpbridge/bin/reconcile.php --minutes=15</code>.</p>'
            . '<form method="post" class="form-inline"><input type="hidden" name="ucp_csrf" value="' . $esc($this->configToken()) . '">'
            . '<label>Bloquées depuis plus de <input type="number" min="1" class="form-control" style="width:80px;display:inline-block" '
            . 'name="reconcile_minutes" value="' . self::RECONCILE_MINUTES . '"> minutes</label> '
            . '<button class="btn btn-default" name="submitUcpbridgeReconcile" value="1">Rapprocher les sessions bloquées</button></form>';
    }

    private function configToken()
    {
        return hash_hmac('sha256', 'bo-config|' . (int) $this->context->employee->id, (string) Configuration::get('UCPBRIDGE_LINK_SECRET'));
    }

    /** Lien de téléchargement de l'export CSV (même page, jeton anti-CSRF du module). */
    private function exportUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name,
            'ucpbridge_export' => 'proofs', 'ucp_csrf' => $this->configToken()]);
    }

    /** Envoie le CSV des preuves et rétractations et termine la requête. */
    private function sendExport()
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . UcpBridgeExport::filename(time()) . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        UcpBridgeExport::write($out);
        fclose($out);
        exit;
    }

    /** Durées de conservation, simulation et purge RGPD, avec le compte rendu de la dernière action. */
    private function retentionPanel(callable $esc)
    {
        $csrf = '<input type="hidden" name="ucp_csrf" value="' . $esc($this->configToken()) . '">';
        $fields = '';
        $days = UcpBridgePurge::durations();
        foreach (UcpBridgeRetention::CATEGORIES as $cat => $c) {
            $min = UcpBridgeRetention::LEGAL_MIN_DAYS[$cat] ?? 0;
            $fields .= '<p><label>' . $esc($c[2]) . ' (jours, défaut ' . (int) $c[1] . ($min ? ', minimum légal ' . (int) $min : '')
                . ', 0 = jamais purgé)</label><br>'
                . '<input type="number" min="0" max="' . UcpBridgeRetention::MAX_DAYS . '" class="form-control" style="width:140px" name="'
                . $esc($c[0]) . '" value="' . (int) $days[$cat] . '"></p>';
        }

        return '<h4>Données personnelles : durées de conservation et purge (RGPD)</h4>'
            . '<p>Jamais touchés : les commandes passées, leurs clients (y compris invités) et leurs adresses ; les sessions en cours '
            . 'de finalisation. Une session terminée par une commande est conservée sans les données personnelles de l\'acheteur. '
            . 'Seuls les clients invités créés par le module et sans aucune commande sont supprimés. À planifier (tâche cron, '
            . 'quotidienne) : <code>php modules/ucpbridge/bin/purge.php</code> (<code>--dry-run</code> pour une simulation).</p>'
            . '<form method="post">' . $csrf . $fields
            . '<button class="btn btn-default" name="submitUcpbridgeRetention" value="1">Enregistrer les durées</button></form>'
            . '<form method="post" class="form-inline" style="margin-top:8px">' . $csrf
            . '<button class="btn btn-default" name="submitUcpbridgePurgeDry" value="1">Simuler la purge</button> '
            . '<button class="btn btn-default" name="submitUcpbridgePurge" value="1" onclick="return confirm(\'Purger maintenant ?\')">'
            . 'Purger maintenant</button></form>';
    }

    /** Enregistrement des durées, simulation ou purge (formulaires de retentionPanel). */
    private function processRetention()
    {
        $actions = ['submitUcpbridgeRetention', 'submitUcpbridgePurge', 'submitUcpbridgePurgeDry'];
        if (!array_filter($actions, [Tools::class, 'isSubmit'])) {
            return '';
        }
        if (!hash_equals($this->configToken(), (string) Tools::getValue('ucp_csrf'))) {
            return $this->displayError('Jeton de sécurité invalide : aucune modification.');
        }
        if (Tools::isSubmit('submitUcpbridgeRetention')) {
            $values = [];
            foreach (UcpBridgeRetention::CATEGORIES as $cat => $c) {
                $v = UcpBridgeRetention::normalizeDays(Tools::getValue($c[0]));
                if (null === $v) {
                    return $this->displayError('Durée de conservation invalide (' . $c[2] . ') : un nombre entier de jours, de 0 à '
                        . UcpBridgeRetention::MAX_DAYS . '. Aucune durée modifiée.');
                }
                if (UcpBridgeRetention::tooShort($cat, $v)) {
                    // Durée légale (paiements, preuves) : jamais sous le plancher, une faute de frappe effacerait des pièces.
                    return $this->displayError(UcpBridgeRetention::tooShortMessage($cat));
                }
                $values[$c[0]] = $v;
            }
            foreach ($values as $k => $v) {
                Configuration::updateValue($k, $v);
            }

            return $this->displayConfirmation('Durées de conservation enregistrées.');
        }
        if ($bad = UcpBridgePurge::refusedDurations()) {
            return $this->displayError(UcpBridgeRetention::purgeRefusedMessage($bad));
        }
        $dry = Tools::isSubmit('submitUcpbridgePurgeDry');
        $lines = '';
        foreach (UcpBridgeRetention::summary((new UcpBridgePurge($dry))->run(), $dry) as $l) {
            $lines .= '<li>' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        return $this->displayConfirmation(($dry ? 'Simulation de la purge (rien n\'a été supprimé)' : 'Compte rendu de la purge') . ' :')
            . '<ul id="ucpbridge-purge-report">' . $lines . '</ul>';
    }

    /** Export des preuves et choix de conservation à la désinstallation. */
    private function evidencePanel(callable $esc)
    {
        $n = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucpbridge_proof`');
        $w = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucpbridge_withdrawal`');

        return '<h4>Journal de preuve et rétractations</h4><p>' . $n . ' preuve(s) de commande, ' . $w . ' demande(s) de rétractation. '
            . 'Conservés à la désinstallation (avec le journal des paiements) et retrouvés à la réinstallation, sauf choix contraire '
            . 'ci-dessus.</p><p><a id="ucpbridge-export" class="btn btn-default" href="' . $esc($this->exportUrl()) . '">'
            . 'Exporter les preuves et rétractations (CSV)</a></p>';
    }

    public function getContent()
    {
        $out = '';
        if ('proofs' === Tools::getValue('ucpbridge_export')) {
            if (hash_equals($this->configToken(), (string) Tools::getValue('ucp_csrf'))) {
                $this->sendExport();
            }
            $out .= $this->displayError('Jeton de sécurité invalide : export non effectué.');
        }
        if (Tools::isSubmit('submitUcpbridgeReconcile')) {
            if (!hash_equals($this->configToken(), (string) Tools::getValue('ucp_csrf'))) {
                $out .= $this->displayError('Jeton de sécurité invalide : rapprochement non effectué.');
            } else {
                $minutes = max(1, (int) Tools::getValue('reconcile_minutes', self::RECONCILE_MINUTES));
                $done = (new UcpBridgeCart($this->context, $this))->reconcile($minutes);
                $lines = '';
                foreach ($done as $d) {
                    $lines .= '<li><code>' . htmlspecialchars($d[0], ENT_QUOTES, 'UTF-8') . '</code> : ' . htmlspecialchars($d[1], ENT_QUOTES, 'UTF-8') . '</li>';
                }
                $out .= $this->displayConfirmation('Rapprochement effectué : ' . count($done) . ' session(s) traitée(s).')
                    . ($lines ? '<ul>' . $lines . '</ul>' : '');
            }
        }
        if (Tools::isSubmit('submitUcpbridgeRevoke')) {
            if (!hash_equals($this->configToken(), (string) Tools::getValue('ucp_csrf'))) {
                $out .= $this->displayError('Jeton de sécurité invalide : révocation non effectuée.');
            } else {
                UcpBridgeOAuth::revokeAllFor((string) Tools::getValue('revoke_client'), (int) Tools::getValue('revoke_customer'));
                $out .= $this->displayConfirmation('Accès révoqué.');
            }
        }
        $out .= $this->processApiKeys();
        $out .= $this->processRetention();
        if (Tools::isSubmit('submitUcpbridge')) {
            if (!hash_equals($this->configToken(), (string) Tools::getValue('ucp_csrf'))) {
                $out .= $this->displayError('Jeton de sécurité invalide : configuration non enregistrée.');
            } else {
                Configuration::updateValue('UCPBRIDGE_MOCK_SECRET', trim((string) Tools::getValue('UCPBRIDGE_MOCK_SECRET')));
                foreach (['UCPBRIDGE_STRIPE_PK', 'UCPBRIDGE_GPAY_MERCHANT_ID'] as $k) {
                    Configuration::updateValue($k, trim((string) Tools::getValue($k)));
                }
                // Clé secrète jamais réaffichée : champ vide = clé inchangée, « - » = clé effacée.
                $sk = trim((string) Tools::getValue('UCPBRIDGE_STRIPE_KEY'));
                if ('-' === $sk) {
                    Configuration::updateValue('UCPBRIDGE_STRIPE_KEY', '');
                } elseif ('' !== $sk) {
                    Configuration::updateValue('UCPBRIDGE_STRIPE_KEY', $sk);
                }
                $pages = $this->legalPages();
                foreach (self::CMS_KEYS as $key) {
                    $id = (int) Tools::getValue($key);
                    Configuration::updateValue($key, isset($pages[$id]) ? $id : 0);
                }
                Configuration::updateValue('UCPBRIDGE_TERMS_REVIEW', Tools::getValue('UCPBRIDGE_TERMS_REVIEW') ? 1 : 0);
                Configuration::updateValue('UCPBRIDGE_UNINSTALL_PURGE', Tools::getValue('UCPBRIDGE_UNINSTALL_PURGE') ? 1 : 0);
                $out .= $this->displayConfirmation('Configuration enregistrée.');
            }
        }
        $base = $this->context->link->getBaseLink();
        $esc = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $field = function ($name, $label) use ($esc) {
            return '<p><label>' . $esc($label) . '</label><br><input type="text" class="form-control" name="' . $esc($name)
                . '" value="' . $esc(Configuration::get($name)) . '"></p>';
        };
        $labels = ['terms_of_service' => 'Conditions générales de vente (terms_of_service)', 'privacy_policy' => 'Confidentialité (privacy_policy)',
            'refund_policy' => 'Retours et rétractation (refund_policy)', 'shipping_policy' => 'Livraison (shipping_policy)'];
        $pages = $this->legalPages();
        $selects = '';
        foreach (self::CMS_KEYS as $type => $key) {
            $current = (int) Configuration::get($key);
            $opts = '<option value="0"' . (0 === $current ? ' selected' : '') . '>(aucune)</option>';
            foreach ($pages as $id => $pg) {
                $opts .= '<option value="' . (int) $id . '"' . ($id === $current ? ' selected' : '') . '>' . $esc($pg['title'])
                    . ($pg['active'] ? '' : ' (inactive)') . '</option>';
            }
            $selects .= '<p><label>' . $esc($labels[$type]) . '</label><br><select class="form-control" name="' . $esc($key) . '">' . $opts . '</select></p>';
        }
        $out .= '<div class="panel"><h3>' . $esc(self::DISPLAY_NAME) . '</h3>'
            . '<div class="alert alert-warning">Prototype de démonstration : ne pas utiliser en production.</div>'
            . '<p>Profil : <code>' . $esc($base) . '.well-known/ucp</code><br>API REST : <code>' . $esc($base) . 'ucp/v1</code><br>'
            . 'MCP : <code>' . $esc($base) . 'ucp/v1/mcp</code></p>'
            . '<form method="post">'
            . '<input type="hidden" name="ucp_csrf" value="' . $esc($this->configToken()) . '">'
            . '<h4>Liens légaux (links) et CGV</h4>' . $selects
            . '<p><label><input type="checkbox" name="UCPBRIDGE_TERMS_REVIEW" value="1"' . (Configuration::get('UCPBRIDGE_TERMS_REVIEW') ? ' checked' : '') . '> '
            . 'Relecture des CGV chez le marchand : une session sans acceptation passe en requires_buyer_review (page signée avec case à cocher)</label></p>'
            . '<h4>Paiement de test</h4>'
            . $field('UCPBRIDGE_MOCK_SECRET', 'Secret de simulation du paiement de test (vide = paiement de test désactivé)')
            . '<h4>Google Pay (com.google.pay, passerelle Stripe, environnement TEST)</h4>'
            . '<p><label>Clé secrète Stripe de test (sk_test_... ; les clés live sont refusées). '
            . (Configuration::get('UCPBRIDGE_STRIPE_KEY') ? 'Une clé est enregistrée : laisser vide pour la conserver, « - » pour l\'effacer.' : 'Aucune clé : Google Pay désactivé.')
            . '</label><br><input type="password" class="form-control" name="UCPBRIDGE_STRIPE_KEY" value="" autocomplete="off"></p>'
            . $field('UCPBRIDGE_STRIPE_PK', 'Clé publiable Stripe (pk_test_...)')
            . '<p>URL de l\'API Stripe : <code>' . $esc(Configuration::get('UCPBRIDGE_STRIPE_API_BASE')) . '</code> (modifiable par script uniquement, pour le banc d\'essai)</p>'
            . $field('UCPBRIDGE_GPAY_MERCHANT_ID', 'Google Pay merchant_id (test)')
            . '<h4>Identity linking (OAuth 2.0)</h4><p>Clients enregistrés : <code>'
            . $esc(implode(', ', array_keys(UcpBridgeOAuth::clients())) ?: 'aucun') . '</code> (enregistrement par script, secret affiché une seule fois)</p>'
            . '<h4>Désinstallation</h4><p><label><input type="checkbox" name="UCPBRIDGE_UNINSTALL_PURGE" value="1"'
            . (Configuration::get('UCPBRIDGE_UNINSTALL_PURGE') ? ' checked' : '') . '> Supprimer aussi le journal de preuve, les demandes '
            . 'de rétractation et le journal des paiements à la prochaine désinstallation (irréversible : exporter d\'abord). '
            . 'Case décochée : ces données sont conservées et retrouvées à la réinstallation.</label></p>'
            . '<button class="btn btn-primary" name="submitUcpbridge" value="1">Enregistrer</button></form>'
            . $this->apiKeysPanel($esc) . $this->grantsPanel($esc) . $this->evidencePanel($esc) . $this->retentionPanel($esc)
            . $this->reconcilePanel($esc) . '</div>';

        return $out;
    }
}
