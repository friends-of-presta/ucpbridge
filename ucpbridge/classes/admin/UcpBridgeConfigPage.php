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
            $out .= $this->module->displayError('Jeton de sécurité invalide : export non effectué.');
        }
        if (Tools::isSubmit('submitUcpbridgeReconcile')) {
            $out .= $this->processReconcile();
        }
        if (Tools::isSubmit('submitUcpbridgeRevoke')) {
            if (!$this->tokenValid()) {
                $out .= $this->module->displayError('Jeton de sécurité invalide : révocation non effectuée.');
            } else {
                UcpBridgeOAuth::revokeAllFor((string) Tools::getValue('revoke_client'), (int) Tools::getValue('revoke_customer'));
                $out .= $this->module->displayConfirmation('Accès révoqué.');
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
            return $this->module->displayError('Jeton de sécurité invalide : rapprochement non effectué.');
        }
        $minutes = max(1, (int) Tools::getValue('reconcile_minutes', Ucpbridge::RECONCILE_MINUTES));
        $done = (new UcpBridgeCart($this->context, $this->module))->reconcile($minutes);

        return $this->module->displayConfirmation('Rapprochement effectué : ' . count($done) . ' session(s) traitée(s).')
            . $this->fetch('report.tpl', ['ucp_report_id' => 'ucpbridge-reconcile-report', 'ucp_report' => array_map(function ($d) {
                return ['code' => $d[0], 'text' => $d[1]];
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
            return $this->module->displayError('Jeton de sécurité invalide : clés API inchangées.');
        }
        $id = (string) Tools::getValue('api_key_id');
        // Identifiant reçu en POST, affiché dans un message que displayConfirmation() n'échappe pas.
        $shown = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        if (Tools::isSubmit('submitUcpbridgeApiKeyDelete')) {
            UcpBridgeApiKeys::remove($id);

            return $this->module->displayConfirmation('Clé de la plateforme « ' . $shown . ' » supprimée : elle est refusée dès maintenant.');
        }
        $key = Tools::isSubmit('submitUcpbridgeApiKeyAdd')
            ? UcpBridgeApiKeys::add($id, (string) Tools::getValue('api_key_label'))
            : UcpBridgeApiKeys::regenerate($id);
        if (null === $key) {
            return $this->module->displayError('Identifiant de plateforme invalide ou déjà utilisé (minuscules, chiffres, - et _, 32 caractères au plus).');
        }

        return $this->module->displayConfirmation('Clé de la plateforme « ' . $shown . ' » : ' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
            . ' (à transmettre à cette plateforme seulement)');
    }

    /** Enregistrement des durées, simulation ou purge. */
    private function processRetention()
    {
        $actions = ['submitUcpbridgeRetention', 'submitUcpbridgePurge', 'submitUcpbridgePurgeDry'];
        if (!array_filter($actions, [Tools::class, 'isSubmit'])) {
            return '';
        }
        if (!$this->tokenValid()) {
            return $this->module->displayError('Jeton de sécurité invalide : aucune modification.');
        }
        if (Tools::isSubmit('submitUcpbridgeRetention')) {
            $values = [];
            foreach (UcpBridgeRetention::CATEGORIES as $cat => $c) {
                $v = UcpBridgeRetention::normalizeDays(Tools::getValue($c[0]));
                if (null === $v) {
                    return $this->module->displayError('Durée de conservation invalide (' . $c[2] . ') : un nombre entier de jours, de 0 à '
                        . UcpBridgeRetention::MAX_DAYS . '. Aucune durée modifiée.');
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

            return $this->module->displayConfirmation('Durées de conservation enregistrées.');
        }
        if ($bad = UcpBridgePurge::refusedDurations()) {
            return $this->module->displayError(UcpBridgeRetention::purgeRefusedMessage($bad));
        }
        $dry = Tools::isSubmit('submitUcpbridgePurgeDry');
        $lines = [];
        foreach (UcpBridgeRetention::summary((new UcpBridgePurge($dry))->run(), $dry) as $l) {
            $lines[] = ['code' => '', 'text' => $l];
        }

        return $this->module->displayConfirmation(($dry ? 'Simulation de la purge (rien n\'a été supprimé)' : 'Compte rendu de la purge') . ' :')
            . $this->fetch('report.tpl', ['ucp_report_id' => 'ucpbridge-purge-report', 'ucp_report' => $lines]);
    }

    /** Réglages : pages légales, relecture des CGV, paiement de test, Stripe et Google Pay, désinstallation. */
    private function processSettings()
    {
        if (!$this->tokenValid()) {
            return $this->module->displayError('Jeton de sécurité invalide : configuration non enregistrée.');
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

        return $this->module->displayConfirmation('Configuration enregistrée.');
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
        $labels = ['terms_of_service' => 'Conditions générales de vente (terms_of_service)', 'privacy_policy' => 'Confidentialité (privacy_policy)',
            'refund_policy' => 'Retours et rétractation (refund_policy)', 'shipping_policy' => 'Livraison (shipping_policy)'];
        $options = [['id' => 0, 'name' => '(aucune)']];
        foreach ($this->module->legalPages() as $id => $pg) {
            $options[] = ['id' => (int) $id, 'name' => $pg['title'] . ($pg['active'] ? '' : ' (inactive)')];
        }
        $legal = [];
        $values = [];
        foreach (Ucpbridge::CMS_KEYS as $type => $key) {
            $legal[] = ['type' => 'select', 'name' => $key, 'label' => $labels[$type],
                'options' => ['query' => $options, 'id' => 'id', 'name' => 'name']];
            $values[$key] = (int) Configuration::get($key);
        }
        $legal[] = ['type' => 'switch', 'name' => 'UCPBRIDGE_TERMS_REVIEW', 'label' => 'Relecture des CGV chez le marchand', 'is_bool' => true,
            'values' => self::yesNo('UCPBRIDGE_TERMS_REVIEW'),
            'desc' => 'Une session sans acceptation passe en requires_buyer_review (page signée avec case à cocher).'];
        $submit = ['title' => 'Enregistrer', 'name' => 'submitUcpbridge'];
        $stripeKey = (bool) Configuration::get('UCPBRIDGE_STRIPE_KEY');
        $clients = implode(', ', array_keys(UcpBridgeOAuth::clients())) ?: 'aucun';
        $fieldsets = [
            ['form' => ['legend' => self::legend('Liens légaux (links) et CGV', 'icon-legal'), 'input' => $legal, 'submit' => $submit]],
            ['form' => ['legend' => self::legend('Paiement de test', 'icon-credit-card'), 'input' => [
                ['type' => 'text', 'name' => 'UCPBRIDGE_MOCK_SECRET', 'label' => 'Secret de simulation du paiement de test',
                    'desc' => 'Vide : paiement de test désactivé.'],
            ], 'submit' => $submit]],
            ['form' => ['legend' => self::legend('Google Pay (com.google.pay, passerelle Stripe, environnement TEST)', 'icon-google'), 'input' => [
                ['type' => 'password', 'name' => 'UCPBRIDGE_STRIPE_KEY', 'label' => 'Clé secrète Stripe de test', 'autocomplete' => false,
                    'desc' => 'sk_test_... ; les clés live sont refusées. ' . ($stripeKey
                        ? 'Une clé est enregistrée : laisser vide pour la conserver, « - » pour l\'effacer.'
                        : 'Aucune clé : Google Pay désactivé.')],
                ['type' => 'text', 'name' => 'UCPBRIDGE_STRIPE_PK', 'label' => 'Clé publiable Stripe (pk_test_...)'],
                ['type' => 'html', 'name' => 'ucp_stripe_base', 'label' => 'URL de l\'API Stripe',
                    'html_content' => $this->fetch('code.tpl', ['ucp_code' => (string) Configuration::get('UCPBRIDGE_STRIPE_API_BASE'),
                        'ucp_note' => 'modifiable par script uniquement, pour le banc d\'essai'])],
                ['type' => 'text', 'name' => 'UCPBRIDGE_GPAY_MERCHANT_ID', 'label' => 'Google Pay merchant_id (test)'],
            ], 'submit' => $submit]],
            ['form' => ['legend' => self::legend('Identity linking (OAuth 2.0)', 'icon-link'), 'input' => [
                ['type' => 'html', 'name' => 'ucp_oauth_clients', 'label' => 'Clients enregistrés',
                    'html_content' => $this->fetch('code.tpl', ['ucp_code' => $clients,
                        'ucp_note' => 'enregistrement par script, secret affiché une seule fois'])],
            ]]],
            ['form' => ['legend' => self::legend('Désinstallation', 'icon-trash'), 'input' => [
                ['type' => 'switch', 'name' => 'UCPBRIDGE_UNINSTALL_PURGE', 'label' => 'Supprimer les preuves à la désinstallation', 'is_bool' => true,
                    'values' => self::yesNo('UCPBRIDGE_UNINSTALL_PURGE'),
                    'desc' => 'Supprimer aussi le journal de preuve, les demandes de rétractation et le journal des paiements à la prochaine '
                        . 'désinstallation (irréversible : exporter d\'abord). Non : ces données sont conservées et retrouvées à la réinstallation.'],
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
        return [['id' => $name . '_on', 'value' => 1, 'label' => 'Oui'], ['id' => $name . '_off', 'value' => 0, 'label' => 'Non']];
    }

    private function addPlatformForm()
    {
        return $this->form('ucpbridge_api_key_add', 'submitUcpbridgeApiKeyAdd', [['form' => [
            'legend' => self::legend('Ajouter une plateforme', 'icon-plus'),
            'input' => [
                ['type' => 'text', 'name' => 'api_key_id', 'label' => 'Identifiant', 'class' => 'fixed-width-xl',
                    'desc' => 'Minuscules, chiffres, - et _, 32 caractères au plus (ex. google).'],
                ['type' => 'text', 'name' => 'api_key_label', 'label' => 'Nom affiché', 'class' => 'fixed-width-xxl'],
            ],
            'submit' => ['title' => 'Ajouter une plateforme', 'name' => 'submitUcpbridgeApiKeyAdd', 'icon' => 'process-icon-new'],
        ]]], ['api_key_id' => '', 'api_key_label' => '']);
    }

    private function retentionForm()
    {
        $inputs = [];
        $values = [];
        $days = UcpBridgePurge::durations();
        foreach (UcpBridgeRetention::CATEGORIES as $cat => $c) {
            $inputs[] = ['type' => 'text', 'name' => $c[0], 'label' => $c[2], 'class' => 'fixed-width-sm', 'suffix' => 'jours',
                'desc' => 'Défaut ' . (int) $c[1] . (isset(UcpBridgeRetention::LEGAL_MIN_DAYS[$cat])
                    ? ', minimum légal ' . (int) UcpBridgeRetention::LEGAL_MIN_DAYS[$cat] : '') . ', 0 = jamais purgé, ' . UcpBridgeRetention::MAX_DAYS . ' au plus.'];
            $values[$c[0]] = (int) $days[$cat];
        }

        return $this->form('ucpbridge_retention', 'submitUcpbridgeRetention', [['form' => [
            'legend' => self::legend('Données personnelles : durées de conservation (RGPD)', 'icon-time'),
            'description' => 'Jamais touchés : les commandes passées, leurs clients (y compris invités) et leurs adresses ; les sessions en cours '
                . 'de finalisation. Une session terminée par une commande est conservée sans les données personnelles de l\'acheteur. '
                . 'Seuls les clients invités créés par le module et sans aucune commande sont supprimés.',
            'input' => $inputs,
            'submit' => ['title' => 'Enregistrer les durées', 'name' => 'submitUcpbridgeRetention'],
        ]]], $values);
    }

    private function purgeForm()
    {
        return $this->form('ucpbridge_purge', 'submitUcpbridgePurgeForm', [['form' => [
            'legend' => self::legend('Purge des données personnelles', 'icon-eraser'),
            'input' => [
                ['type' => 'html', 'name' => 'ucp_purge_cron', 'label' => 'Tâche planifiée (quotidienne)',
                    'html_content' => $this->fetch('code.tpl', ['ucp_code' => 'php modules/ucpbridge/bin/purge.php',
                        'ucp_note' => '--dry-run pour une simulation'])],
            ],
            'buttons' => [
                ['type' => 'submit', 'name' => 'submitUcpbridgePurgeDry', 'title' => 'Simuler la purge', 'icon' => 'process-icon-preview'],
                ['type' => 'submit', 'name' => 'submitUcpbridgePurge', 'title' => 'Purger maintenant', 'icon' => 'process-icon-delete',
                    'js' => 'return confirm(\'Purger maintenant ?\')'],
            ],
        ]]], []);
    }

    private function reconcileForm()
    {
        $n = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucpbridge_session` WHERE kind = \'checkout\'
            AND status IN (\'complete_in_progress\', \'reconciling\', \'updating\') AND date_upd < \''
            . pSQL(date('Y-m-d H:i:s', time() - 60 * Ucpbridge::RECONCILE_MINUTES)) . '\'');

        return $this->form('ucpbridge_reconcile', 'submitUcpbridgeReconcile', [['form' => [
            'legend' => self::legend('Rapprocher les sessions bloquées', 'icon-refresh'),
            'description' => 'Sessions réservées depuis plus de ' . Ucpbridge::RECONCILE_MINUTES . ' minutes : ' . $n . '. Commande trouvée : '
                . 'session terminée ; débit sans commande : remboursement et incident ; débit incertain : incident à vérifier chez le '
                . 'prestataire ; aucun débit : session rendue.',
            'input' => [
                ['type' => 'text', 'name' => 'reconcile_minutes', 'label' => 'Bloquées depuis plus de', 'class' => 'fixed-width-sm',
                    'suffix' => 'minutes'],
                ['type' => 'html', 'name' => 'ucp_reconcile_cron', 'label' => 'Tâche planifiée',
                    'html_content' => $this->fetch('code.tpl', ['ucp_code' => 'php modules/ucpbridge/bin/reconcile.php --minutes=15', 'ucp_note' => ''])],
            ],
            'submit' => ['title' => 'Rapprocher les sessions bloquées', 'name' => 'submitUcpbridgeReconcile'],
        ]]], ['reconcile_minutes' => Ucpbridge::RECONCILE_MINUTES]);
    }
}
