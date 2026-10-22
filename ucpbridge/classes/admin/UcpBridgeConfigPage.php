<?php
/**
 * Page de configuration du module (back-office, AdminModules&configure=ucpbridge) : formulaires HelperForm, listes en
 * gabarits Smarty (views/templates/admin/), traitement des envois. Chaque envoi exige le jeton anti-CSRF du module
 * (ucp_csrf, HMAC lié à l'employé connecté), en plus du jeton d'administration de PrestaShop.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeConfigPage
{
    /** @var Ucpbridge */
    private $module;
    /** @var Context */
    private $context;

    public function __construct(Ucpbridge $module, Context $context)
    {
        $this->module = $module;
        $this->context = $context;
    }

    /** Page complète : comptes rendus des envois traités, puis formulaires et listes. */
    public function content()
    {
        $out = $this->process();

        return $out . $this->render();
    }

    /* ============================== Jeton anti-CSRF ============================== */

    public function token()
    {
        return hash_hmac('sha256', 'bo-config|' . (int) $this->context->employee->id, (string) Configuration::get('UCPBRIDGE_LINK_SECRET'));
    }

    private function tokenValid()
    {
        return hash_equals($this->token(), (string) Tools::getValue('ucp_csrf'));
    }

    /* ================================ Traitement ================================ */

    private function process()
    {
        $out = '';
        if ('proofs' === Tools::getValue('ucpbridge_export')) {
            if ($this->tokenValid()) {
                $this->sendExport();
            }
            $out .= $this->module->displayError(UcpBridgeI18n::admin('Invalid security token: export not performed.'));
        }
        if (Tools::isSubmit('submitUcpbridgeReconcile')) {
            $out .= $this->processReconcile();
        }
        if (Tools::isSubmit('submitUcpbridgeRevoke')) {
            if (!$this->tokenValid()) {
                $out .= $this->module->displayError(UcpBridgeI18n::admin('Invalid security token: access not revoked.'));
            } else {
                UcpBridgeOAuth::revokeAllFor((string) Tools::getValue('revoke_client'), (int) Tools::getValue('revoke_customer'));
                $out .= $this->module->displayConfirmation(UcpBridgeI18n::admin('Access revoked.'));
            }
        }
        $out .= $this->processApiKeys();
        $out .= $this->processRetention();
        if (Tools::isSubmit('submitUcpbridge')) {
            $out .= $this->processSettings();
        }

        return $out;
    }

    private function processReconcile()
    {
        if (!$this->tokenValid()) {
            return $this->module->displayError(UcpBridgeI18n::admin('Invalid security token: reconciliation not performed.'));
        }
        $minutes = max(1, (int) Tools::getValue('reconcile_minutes', Ucpbridge::RECONCILE_MINUTES));
        $done = (new UcpBridgeCart($this->context, $this->module))->reconcile($minutes);

        return $this->module->displayConfirmation(UcpBridgeI18n::admin('Reconciliation done: %count% session(s) processed.', ['%count%' => count($done)]))
            . $this->fetch('report.tpl', ['ucp_report_id' => 'ucpbridge-reconcile-report', 'ucp_report' => array_map(function ($d) {
                return ['key' => $d[2], 'code' => $d[0], 'text' => $d[1]];
            }, $done)]);
    }

    /** Plateformes et clés API (mode invité) : ajout, régénération, suppression. */
    private function processApiKeys()
    {
        $actions = ['submitUcpbridgeApiKeyAdd', 'submitUcpbridgeApiKeyRegen', 'submitUcpbridgeApiKeyDelete'];
        if (!array_filter($actions, [Tools::class, 'isSubmit'])) {
            return '';
        }
        if (!$this->tokenValid()) {
            return $this->module->displayError(UcpBridgeI18n::admin('Invalid security token: API keys unchanged.'));
        }
        $id = (string) Tools::getValue('api_key_id');
        // Identifiant reçu en POST, affiché dans un message que displayConfirmation() n'échappe pas.
        $shown = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        if (Tools::isSubmit('submitUcpbridgeApiKeyDelete')) {
            UcpBridgeApiKeys::remove($id);

            return $this->module->displayConfirmation(UcpBridgeI18n::admin('Key of platform "%id%" deleted: it is refused from now on.', ['%id%' => $shown]));
        }
        $key = Tools::isSubmit('submitUcpbridgeApiKeyAdd')
            ? UcpBridgeApiKeys::add($id, (string) Tools::getValue('api_key_label'))
            : UcpBridgeApiKeys::regenerate($id);
        if (null === $key) {
            return $this->module->displayError(UcpBridgeI18n::admin('Invalid or already used platform identifier (lowercase letters, digits, - and _, 32 characters at most).'));
        }

        return $this->module->displayConfirmation(UcpBridgeI18n::admin('Key of platform "%id%": %key% (to be given to this platform only)',
            ['%id%' => $shown, '%key%' => htmlspecialchars($key, ENT_QUOTES, 'UTF-8')]));
    }

    /** Enregistrement des durées, simulation ou purge. */
    private function processRetention()
    {
        $actions = ['submitUcpbridgeRetention', 'submitUcpbridgePurge', 'submitUcpbridgePurgeDry'];
        if (!array_filter($actions, [Tools::class, 'isSubmit'])) {
            return '';
        }
        if (!$this->tokenValid()) {
            return $this->module->displayError(UcpBridgeI18n::admin('Invalid security token: nothing changed.'));
        }
        if (Tools::isSubmit('submitUcpbridgeRetention')) {
            $values = [];
            foreach (UcpBridgeRetention::CATEGORIES as $cat => $c) {
                $v = UcpBridgeRetention::normalizeDays(Tools::getValue($c[0]));
                if (null === $v) {
                    return $this->module->displayError(UcpBridgeI18n::admin('Invalid retention period (%category%): a whole number of days, from 0 to %max%. No period changed.', ['%category%' => UcpBridgeRetention::label($cat), '%max%' => UcpBridgeRetention::MAX_DAYS]));
                }
                if (UcpBridgeRetention::tooShort($cat, $v)) {
                    // Durée légale (paiements, preuves) : jamais sous le plancher, une faute de frappe effacerait des pièces.
                    return $this->module->displayError(UcpBridgeRetention::tooShortMessage($cat));
                }
                $values[$c[0]] = $v;
            }
            foreach ($values as $k => $v) {
                Configuration::updateValue($k, $v);
            }

            return $this->module->displayConfirmation(UcpBridgeI18n::admin('Retention periods saved.'));
        }
        if ($bad = UcpBridgePurge::refusedDurations()) {
            return $this->module->displayError(UcpBridgeRetention::purgeRefusedMessage($bad));
        }
        $dry = Tools::isSubmit('submitUcpbridgePurgeDry');
        $lines = [];
        foreach (UcpBridgeRetention::summary((new UcpBridgePurge($dry))->run(), $dry) as $key => $l) {
            $lines[] = ['key' => $key, 'code' => '', 'text' => $l];
        }

        return $this->module->displayConfirmation($dry ? UcpBridgeI18n::admin('Purge simulation (nothing was deleted):') : UcpBridgeI18n::admin('Purge report:'))
            . $this->fetch('report.tpl', ['ucp_report_id' => 'ucpbridge-purge-report', 'ucp_report' => $lines,
                'ucp_report_mode' => $dry ? 'dry-run' : 'purge']);
    }

    /** Réglages : pages légales, relecture des CGV, paiement de test, Stripe et Google Pay, désinstallation. */
    private function processSettings()
    {
        if (!$this->tokenValid()) {
            return $this->module->displayError(UcpBridgeI18n::admin('Invalid security token: configuration not saved.'));
        }
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
        $pages = $this->module->legalPages();
        foreach (Ucpbridge::CMS_KEYS as $key) {
            $id = (int) Tools::getValue($key);
            Configuration::updateValue($key, isset($pages[$id]) ? $id : 0);
        }
        Configuration::updateValue('UCPBRIDGE_TERMS_REVIEW', Tools::getValue('UCPBRIDGE_TERMS_REVIEW') ? 1 : 0);
        Configuration::updateValue('UCPBRIDGE_UNINSTALL_PURGE', Tools::getValue('UCPBRIDGE_UNINSTALL_PURGE') ? 1 : 0);

        return $this->module->displayConfirmation(UcpBridgeI18n::admin('Configuration saved.'));
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

    /* ================================== Rendu ================================== */

    private function render()
    {
        $base = $this->context->link->getBaseLink();

        return $this->fetch('intro.tpl', [
                'ucp_name' => Ucpbridge::DISPLAY_NAME,
                'ucp_profile' => $base . '.well-known/ucp',
                'ucp_api' => $base . 'ucp/v1',
                'ucp_mcp' => $base . 'ucp/v1/mcp',
            ])
            . $this->settingsForm()
            . $this->fetch('api_keys.tpl', ['ucp_keys' => UcpBridgeApiKeys::all(), 'ucp_csrf' => $this->token()])
            . $this->addPlatformForm()
            . $this->fetch('grants.tpl', ['ucp_grants' => UcpBridgeOAuth::grants(), 'ucp_csrf' => $this->token()])
            . $this->fetch('evidence.tpl', [
                'ucp_proofs' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucpbridge_proof`'),
                'ucp_withdrawals' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucpbridge_withdrawal`'),
                'ucp_export_url' => $this->exportUrl(),
            ])
            . $this->retentionForm()
            . $this->purgeForm()
            . $this->reconcileForm();
    }

    /** Lien de téléchargement de l'export CSV (même page, jeton anti-CSRF du module). */
    private function exportUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->module->name,
            'ucpbridge_export' => 'proofs', 'ucp_csrf' => $this->token()]);
    }

    /** Gabarit Smarty de views/templates/admin/. */
    private function fetch($template, array $vars)
    {
        return $this->module->renderTemplate('admin/' . $template, $vars);
    }

    /**
     * Formulaire HelperForm : un formulaire HTML par appel (les boutons d'un formulaire ne soumettent jamais les champs
     * d'un autre). Le jeton anti-CSRF du module est ajouté en champ caché.
     */
    private function form($id, $submitAction, array $fieldsets, array $values)
    {
        // Hors contrôleur d'administration (ligne de commande, tests), Smarty ne connaît pas encore les gabarits du
        // back-office : on lui donne ceux du thème d'administration par défaut.
        if (!($this->context->controller instanceof AdminController) && defined('_PS_BO_ALL_THEMES_DIR_')) {
            $this->context->smarty->addTemplateDir(_PS_BO_ALL_THEMES_DIR_ . 'default/template');
        }
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->module->name;
        $helper->identifier = 'id_ucpbridge';
        $helper->table = 'ucpbridge';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = $submitAction;
        $helper->show_toolbar = false;
        $helper->currentIndex = AdminController::$currentIndex
            ? AdminController::$currentIndex . '&configure=' . $this->module->name . '&tab_module=' . $this->module->tab . '&module_name=' . $this->module->name
            : '';
        $helper->token = AdminController::$currentIndex ? Tools::getAdminTokenLite('AdminModules') : '';
        foreach ($fieldsets as $i => $f) {
            if (0 === $i) {
                $fieldsets[$i]['form']['id_form'] = $id;
                $fieldsets[$i]['form']['input'][] = ['type' => 'hidden', 'name' => 'ucp_csrf'];
            }
        }
        $helper->fields_value = $values + ['ucp_csrf' => $this->token()];

        return $helper->generateForm($fieldsets);
    }

    private static function legend($title, $icon)
    {
        return ['title' => $title, 'icon' => $icon];
    }

    private function settingsForm()
    {
        $labels = ['terms_of_service' => UcpBridgeI18n::admin('Terms of sale (terms_of_service)'),
            'privacy_policy' => UcpBridgeI18n::admin('Privacy (privacy_policy)'),
            'refund_policy' => UcpBridgeI18n::admin('Returns and withdrawal (refund_policy)'),
            'shipping_policy' => UcpBridgeI18n::admin('Shipping (shipping_policy)')];
        $options = [['id' => 0, 'name' => UcpBridgeI18n::admin('(none)')]];
        foreach ($this->module->legalPages() as $id => $pg) {
            $options[] = ['id' => (int) $id, 'name' => $pg['active'] ? $pg['title'] : UcpBridgeI18n::admin('%title% (inactive)', ['%title%' => $pg['title']])];
        }
        $legal = [];
        $values = [];
        foreach (Ucpbridge::CMS_KEYS as $type => $key) {
            $legal[] = ['type' => 'select', 'name' => $key, 'label' => $labels[$type],
                'options' => ['query' => $options, 'id' => 'id', 'name' => 'name']];
            $values[$key] = (int) Configuration::get($key);
        }
        $legal[] = ['type' => 'switch', 'name' => 'UCPBRIDGE_TERMS_REVIEW', 'label' => UcpBridgeI18n::admin('Terms of sale reviewed on the merchant\'s shop'), 'is_bool' => true,
            'values' => self::yesNo('UCPBRIDGE_TERMS_REVIEW'),
            'desc' => UcpBridgeI18n::admin('A session without acceptance goes to requires_buyer_review (signed page with a checkbox).')];
        $submit = ['title' => UcpBridgeI18n::admin('Save'), 'name' => 'submitUcpbridge'];
        $stripeKey = (bool) Configuration::get('UCPBRIDGE_STRIPE_KEY');
        $clients = implode(', ', array_keys(UcpBridgeOAuth::clients())) ?: UcpBridgeI18n::admin('none');
        $fieldsets = [
            ['form' => ['legend' => self::legend(UcpBridgeI18n::admin('Legal links (links) and terms of sale'), 'icon-legal'), 'input' => $legal, 'submit' => $submit]],
            ['form' => ['legend' => self::legend(UcpBridgeI18n::admin('Test payment'), 'icon-credit-card'), 'input' => [
                ['type' => 'text', 'name' => 'UCPBRIDGE_MOCK_SECRET', 'label' => UcpBridgeI18n::admin('Test payment simulation secret'),
                    'desc' => UcpBridgeI18n::admin('Empty: test payment disabled.')],
            ], 'submit' => $submit]],
            ['form' => ['legend' => self::legend(UcpBridgeI18n::admin('Google Pay (com.google.pay, Stripe gateway, TEST environment)'), 'icon-google'), 'input' => [
                ['type' => 'password', 'name' => 'UCPBRIDGE_STRIPE_KEY', 'label' => UcpBridgeI18n::admin('Stripe test secret key'), 'autocomplete' => false,
                    'desc' => $stripeKey
                        ? UcpBridgeI18n::admin('sk_test_... ; live keys are refused. A key is saved: leave empty to keep it, "-" to delete it.')
                        : UcpBridgeI18n::admin('sk_test_... ; live keys are refused. No key: Google Pay disabled.')],
                ['type' => 'text', 'name' => 'UCPBRIDGE_STRIPE_PK', 'label' => UcpBridgeI18n::admin('Stripe publishable key (pk_test_...)')],
                ['type' => 'html', 'name' => 'ucp_stripe_base', 'label' => UcpBridgeI18n::admin('Stripe API URL'),
                    'html_content' => $this->fetch('code.tpl', ['ucp_code' => (string) Configuration::get('UCPBRIDGE_STRIPE_API_BASE'),
                        'ucp_note' => UcpBridgeI18n::admin('can be changed by script only, for the test bench')])],
                ['type' => 'text', 'name' => 'UCPBRIDGE_GPAY_MERCHANT_ID', 'label' => 'Google Pay merchant_id (test)'],
            ], 'submit' => $submit]],
            ['form' => ['legend' => self::legend('Identity linking (OAuth 2.0)', 'icon-link'), 'input' => [
                ['type' => 'html', 'name' => 'ucp_oauth_clients', 'label' => UcpBridgeI18n::admin('Registered clients'),
                    'html_content' => $this->fetch('code.tpl', ['ucp_code' => $clients,
                        'ucp_note' => UcpBridgeI18n::admin('registered by script, secret shown only once')])],
            ]]],
            ['form' => ['legend' => self::legend(UcpBridgeI18n::admin('Uninstallation'), 'icon-trash'), 'input' => [
                ['type' => 'switch', 'name' => 'UCPBRIDGE_UNINSTALL_PURGE', 'label' => UcpBridgeI18n::admin('Delete the evidence on uninstallation'), 'is_bool' => true,
                    'values' => self::yesNo('UCPBRIDGE_UNINSTALL_PURGE'),
                    'desc' => UcpBridgeI18n::admin('Also delete the evidence log, the withdrawal requests and the payment log on the next uninstallation (irreversible: export first). No: this data is kept and found again on reinstallation.')],
            ], 'submit' => $submit]],
        ];
        $values += [
            'UCPBRIDGE_TERMS_REVIEW' => (int) (bool) Configuration::get('UCPBRIDGE_TERMS_REVIEW'),
            'UCPBRIDGE_MOCK_SECRET' => (string) Configuration::get('UCPBRIDGE_MOCK_SECRET'),
            'UCPBRIDGE_STRIPE_KEY' => '',
            'UCPBRIDGE_STRIPE_PK' => (string) Configuration::get('UCPBRIDGE_STRIPE_PK'),
            'UCPBRIDGE_GPAY_MERCHANT_ID' => (string) Configuration::get('UCPBRIDGE_GPAY_MERCHANT_ID'),
            'UCPBRIDGE_UNINSTALL_PURGE' => (int) (bool) Configuration::get('UCPBRIDGE_UNINSTALL_PURGE'),
        ];

        return $this->form('ucpbridge_settings', 'submitUcpbridge', $fieldsets, $values);
    }

    private static function yesNo($name)
    {
        return [['id' => $name . '_on', 'value' => 1, 'label' => UcpBridgeI18n::admin('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => UcpBridgeI18n::admin('No')]];
    }

    private function addPlatformForm()
    {
        return $this->form('ucpbridge_api_key_add', 'submitUcpbridgeApiKeyAdd', [['form' => [
            'legend' => self::legend(UcpBridgeI18n::admin('Add a platform'), 'icon-plus'),
            'input' => [
                ['type' => 'text', 'name' => 'api_key_id', 'label' => UcpBridgeI18n::admin('Identifier'), 'class' => 'fixed-width-xl',
                    'desc' => UcpBridgeI18n::admin('Lowercase letters, digits, - and _, 32 characters at most (e.g. google).')],
                ['type' => 'text', 'name' => 'api_key_label', 'label' => UcpBridgeI18n::admin('Display name'), 'class' => 'fixed-width-xxl'],
            ],
            'submit' => ['title' => UcpBridgeI18n::admin('Add a platform'), 'name' => 'submitUcpbridgeApiKeyAdd', 'icon' => 'process-icon-new'],
        ]]], ['api_key_id' => '', 'api_key_label' => '']);
    }

    private function retentionForm()
    {
        $inputs = [];
        $values = [];
        $days = UcpBridgePurge::durations();
        foreach (UcpBridgeRetention::CATEGORIES as $cat => $c) {
            $inputs[] = ['type' => 'text', 'name' => $c[0], 'label' => UcpBridgeRetention::label($cat), 'class' => 'fixed-width-sm',
                'suffix' => UcpBridgeI18n::admin('days'),
                'desc' => isset(UcpBridgeRetention::LEGAL_MIN_DAYS[$cat])
                    ? UcpBridgeI18n::admin('Default %default%, legal minimum %min%, 0 = never purged, %max% at most.', ['%default%' => (int) $c[1],
                        '%min%' => (int) UcpBridgeRetention::LEGAL_MIN_DAYS[$cat], '%max%' => UcpBridgeRetention::MAX_DAYS])
                    : UcpBridgeI18n::admin('Default %default%, 0 = never purged, %max% at most.', ['%default%' => (int) $c[1], '%max%' => UcpBridgeRetention::MAX_DAYS])];
            $values[$c[0]] = (int) $days[$cat];
        }

        return $this->form('ucpbridge_retention', 'submitUcpbridgeRetention', [['form' => [
            'legend' => self::legend(UcpBridgeI18n::admin('Personal data: retention periods (GDPR)'), 'icon-time'),
            'description' => UcpBridgeI18n::admin('Never touched: placed orders, their customers (guests included) and their addresses; sessions being completed. A session completed by an order is kept without the personal data of the buyer. Only guest customers created by the module and without any order are deleted.'),
            'input' => $inputs,
            'submit' => ['title' => UcpBridgeI18n::admin('Save the retention periods'), 'name' => 'submitUcpbridgeRetention'],
        ]]], $values);
    }

    private function purgeForm()
    {
        return $this->form('ucpbridge_purge', 'submitUcpbridgePurgeForm', [['form' => [
            'legend' => self::legend(UcpBridgeI18n::admin('Purge of personal data'), 'icon-eraser'),
            'input' => [
                ['type' => 'html', 'name' => 'ucp_purge_cron', 'label' => UcpBridgeI18n::admin('Scheduled task (daily)'),
                    'html_content' => $this->fetch('code.tpl', ['ucp_code' => 'php modules/ucpbridge/bin/purge.php',
                        'ucp_note' => UcpBridgeI18n::admin('--dry-run for a simulation')])],
            ],
            'buttons' => [
                ['type' => 'submit', 'name' => 'submitUcpbridgePurgeDry', 'title' => UcpBridgeI18n::admin('Simulate the purge'), 'icon' => 'process-icon-preview'],
                ['type' => 'submit', 'name' => 'submitUcpbridgePurge', 'title' => UcpBridgeI18n::admin('Purge now'), 'icon' => 'process-icon-delete',
                    'js' => 'return confirm(' . htmlspecialchars(json_encode(UcpBridgeI18n::admin('Purge now?'), JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') . ')'],
            ],
        ]]], []);
    }

    private function reconcileForm()
    {
        $n = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucpbridge_session` WHERE kind = \'checkout\'
            AND status IN (\'complete_in_progress\', \'reconciling\', \'updating\') AND date_upd < \''
            . pSQL(date('Y-m-d H:i:s', time() - 60 * Ucpbridge::RECONCILE_MINUTES)) . '\'');

        return $this->form('ucpbridge_reconcile', 'submitUcpbridgeReconcile', [['form' => [
            'legend' => self::legend(UcpBridgeI18n::admin('Reconcile stuck sessions'), 'icon-refresh'),
            'description' => UcpBridgeI18n::admin('Sessions reserved for more than %minutes% minutes: %count%. Order found: session completed; charge without order: refund and incident; uncertain charge: incident to check with the payment provider; no charge: session released.', ['%minutes%' => Ucpbridge::RECONCILE_MINUTES, '%count%' => $n]),
            'input' => [
                ['type' => 'text', 'name' => 'reconcile_minutes', 'label' => UcpBridgeI18n::admin('Stuck for more than'), 'class' => 'fixed-width-sm',
                    'suffix' => UcpBridgeI18n::admin('minutes')],
                ['type' => 'html', 'name' => 'ucp_reconcile_cron', 'label' => UcpBridgeI18n::admin('Scheduled task'),
                    'html_content' => $this->fetch('code.tpl', ['ucp_code' => 'php modules/ucpbridge/bin/reconcile.php --minutes=15', 'ucp_note' => ''])],
            ],
            'submit' => ['title' => UcpBridgeI18n::admin('Reconcile stuck sessions'), 'name' => 'submitUcpbridgeReconcile'],
        ]]], ['reconcile_minutes' => Ucpbridge::RECONCILE_MINUTES]);
    }
}
