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

require_once __DIR__ . '/classes/UcpBridgeI18n.php';
require_once __DIR__ . '/classes/UcpBridgeItemRef.php';
require_once __DIR__ . '/classes/UcpBridgeTotals.php';
require_once __DIR__ . '/classes/UcpBridgeSigner.php';
require_once __DIR__ . '/classes/UcpBridgePkce.php';
require_once __DIR__ . '/classes/UcpBridgeConsent.php';
require_once __DIR__ . '/classes/UcpBridgeLegal.php';
require_once __DIR__ . '/classes/UcpBridgeCatalog.php';
require_once __DIR__ . '/classes/cart/UcpBridgeCaller.php';
require_once __DIR__ . '/classes/cart/UcpBridgeSessionStore.php';
require_once __DIR__ . '/classes/cart/UcpBridgeMessage.php';
require_once __DIR__ . '/classes/cart/UcpBridgeCartLines.php';
require_once __DIR__ . '/classes/cart/UcpBridgeBuyer.php';
require_once __DIR__ . '/classes/cart/UcpBridgeFulfillment.php';
require_once __DIR__ . '/classes/cart/UcpBridgeCheckoutStatus.php';
require_once __DIR__ . '/classes/cart/UcpBridgeCheckoutView.php';
require_once __DIR__ . '/classes/cart/UcpBridgeCheckoutCompletion.php';
require_once __DIR__ . '/classes/cart/UcpBridgeReconciler.php';
require_once __DIR__ . '/classes/cart/UcpBridgeOrderView.php';
require_once __DIR__ . '/classes/cart/UcpBridgeCartSession.php';
require_once __DIR__ . '/classes/cart/UcpBridgeCheckoutSession.php';
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
require_once __DIR__ . '/classes/admin/UcpBridgeConfigPage.php';
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
        // Seule version éprouvée par la suite de tests : PrestaShop 9.1 (image 9.1.5, PHP 8.5).
        $this->ps_versions_compliancy = ['min' => '9.1.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = self::DISPLAY_NAME;
        $this->description = UcpBridgeI18n::admin('Universal Commerce Protocol: profile, transferable cart, native checkout, variants, terms of sale and evidence, MCP.');
    }

    /** Nouveau système de traduction (domaines Modules.Ucpbridge.*, catalogues XLIFF de translations/). */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function install()
    {
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
        $h->title = UcpBridgeI18n::admin('%name%: registration of payment adapters', ['%name%' => self::DISPLAY_NAME]);
        $h->description = UcpBridgeI18n::admin('Parameter registry (UcpBridgePaymentRegistry): call $params[\'registry\']->register(new MyAdapter())');
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
        // Langue de l'e-mail (celle de la commande), pas forcément celle du contexte.
        $locale = UcpBridgeI18n::localeOf((int) ($params['id_lang'] ?? 0));
        $links = $this->legalLinks();
        $withdraw = $this->withdrawalUrl($idOrder);
        $items = '';
        $legal = UcpBridgeI18n::shop('Legal information', [], $locale);
        $right = UcpBridgeI18n::shop('Right of withdrawal: you have 14 days from receipt of your order to withdraw, without having to give any reason.', [], $locale);
        $button = UcpBridgeI18n::shop('Withdraw from this order', [], $locale);
        $txt = $legal . "\n";
        foreach (array_unique(array_column($links, 'title', 'url')) as $url => $title) {
            $l = ['url' => $url, 'title' => $title];
            $items .= '<li><a href="' . $e($l['url']) . '">' . $e($l['title']) . '</a></li>';
            $txt .= '- ' . $l['title'] . ' : ' . $l['url'] . "\n";
        }
        $params['extra_template_vars']['{ucpbridge_legal_html}'] = '<table width="100%" style="max-width:600px;margin:16px auto;font-family:Arial,sans-serif;font-size:14px;color:#333">'
            . '<tr><td style="padding:16px;border-top:1px solid #ddd"><p style="font-weight:bold;margin:0 0 8px">' . $e($legal) . '</p>'
            . '<ul style="margin:0 0 12px;padding-left:18px">' . $items . '</ul>'
            . '<p style="margin:0 0 8px">' . $e($right) . '</p>'
            . '<p style="margin:0"><a href="' . $e($withdraw) . '" style="display:inline-block;padding:8px 14px;border:1px solid #31249F;'
            . 'border-radius:6px;color:#31249F;text-decoration:none">' . $e($button) . '</a></p></td></tr></table>';
        $params['extra_template_vars']['{ucpbridge_legal_txt}'] = $txt
            . UcpBridgeI18n::shop('Right of withdrawal: 14 days from receipt of your order.', [], $locale) . "\n"
            . UcpBridgeI18n::shop('Withdraw from this order: %url%', ['%url%' => $withdraw], $locale) . "\n";
    }

    /* ---------- Mes accès agents (compte client) ---------- */

    public function accessUrl()
    {
        return $this->context->link->getModuleLink('ucpbridge', 'access');
    }

    /** Lien « Mes accès agents » sur la page Mon compte : liste et révocation des assistants reliés. */
    public function hookDisplayCustomerAccount($params)
    {
        return $this->renderTemplate('hook/customer_account.tpl', ['ucp_access_url' => $this->accessUrl()]);
    }

    /* ---------- Journal de preuve sur la fiche commande (back-office) ---------- */

    public function hookDisplayAdminOrderMain($params)
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        $p = UcpBridgeProof::get($idOrder);
        if (!$p) {
            return '';
        }
        $euros = function ($c) {
            return number_format(((int) $c) / 100, 2, ',', ' ') . ' €';
        };
        $rows = [];
        $rows['date'] = [UcpBridgeI18n::admin('Date'), $p['date_add']];
        $rows['agent'] = [UcpBridgeI18n::admin('Agent (UCP-Agent)'), $p['agent'] ?: UcpBridgeI18n::admin('(not sent)')];
        $rows['api_call'] = [UcpBridgeI18n::admin('API call that completed the order'), trim(($p['caller'] ?? '') . ', IP ' . ($p['api_ip'] ?? '') . ', ' . ($p['api_user_agent'] ?? ''), ', ')];
        $rows['session'] = [UcpBridgeI18n::admin('Checkout session'), $p['session_id']];
        $rows['terms_version'] = [UcpBridgeI18n::admin('Version of the terms of sale presented'), $p['terms_version']];
        $acc = $p['terms_acceptance'];
        $rows['terms_acceptance'] = [UcpBridgeI18n::admin('Terms of sale box ticked on the merchant\'s page'), $acc
            ? (isset($p['api_ip']) && $acc['ip'] === $p['api_ip']
                ? UcpBridgeI18n::admin('on %date%, version %version%, IP %ip% (SAME IP as the agent\'s API call). Warning: non-nominative evidence, the box may have been ticked by the agent itself.', ['%date%' => $acc['accepted_at'], '%version%' => $acc['version'], '%ip%' => $acc['ip']])
                : UcpBridgeI18n::admin('on %date%, version %version%, IP %ip%. Warning: non-nominative evidence, the box may have been ticked by the agent itself.', ['%date%' => $acc['accepted_at'], '%version%' => $acc['version'], '%ip%' => $acc['ip']]))
            : UcpBridgeI18n::admin('not requested (review disabled)')];
        $cons = [];
        foreach ((array) $p['consent'] as $k => $v) {
            $cons[] = $k . ' : ' . (null === $v ? UcpBridgeI18n::admin('not expressed') : ($v ? UcpBridgeI18n::admin('yes') : UcpBridgeI18n::admin('no')));
        }
        $rows['consent'] = [UcpBridgeI18n::admin('Consents (buyer_consent)'), implode(' ; ', $cons)];
        $w = UcpBridgeProof::withdrawal($idOrder);
        $rows['withdrawal'] = [UcpBridgeI18n::admin('Online withdrawal'), !$w ? UcpBridgeI18n::admin('no request')
            : ($w['email_sent'] ? UcpBridgeI18n::admin('requested on %date% (IP %ip%), acknowledgement sent', ['%date%' => $w['date_add'], '%ip%' => $w['ip']])
                : UcpBridgeI18n::admin('requested on %date% (IP %ip%)', ['%date%' => $w['date_add'], '%ip%' => $w['ip']]))];
        $links = [];
        foreach ((array) $p['links'] as $l) {
            $links[] = ['type' => (string) $l['type'], 'url' => (string) $l['url'], 'title' => (string) ($l['title'] ?? $l['url'])];
        }
        $disclosures = [];
        foreach ((array) $p['disclosures'] as $d) {
            $disclosures[] = ['code' => (string) $d['code'], 'content' => (string) $d['content']];
        }
        $totals = [];
        foreach ((array) $p['totals'] as $t) {
            $totals[] = ['label' => (string) ($t['display_text'] ?? $t['type']), 'amount' => $euros($t['amount'])];
        }

        return $this->renderTemplate('hook/order_proof.tpl', [
            'ucp_rows' => array_map(function ($k, $r) {
                return ['key' => $k, 'label' => $r[0], 'value' => (string) $r[1]];
            }, array_keys($rows), $rows),
            // État stable de la rétractation (lu par les tests et les intégrations, indépendant de la langue).
            'ucp_withdrawal_state' => $w ? 'requested' : 'none',
            'ucp_links' => $links,
            'ucp_disclosures' => $disclosures,
            'ucp_totals' => $totals,
        ]);
    }

    /** Gabarit Smarty du module (views/templates/), variables assignées pour ce rendu seulement. */
    public function renderTemplate($template, array $vars)
    {
        $tpl = $this->context->smarty->createTemplate($this->getLocalPath() . 'views/templates/' . $template, $this->context->smarty);
        $tpl->assign($vars);

        return $tpl->fetch();
    }

    /* ---------- Page de configuration (voir UcpBridgeConfigPage) ---------- */

    public function getContent()
    {
        return (new UcpBridgeConfigPage($this, $this->context))->content();
    }
}
