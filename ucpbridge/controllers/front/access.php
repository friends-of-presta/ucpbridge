<?php
/**
 * « Mes accès agents » (compte client) : liste des assistants reliés au compte par identity linking (OAuth)
 * et révocation, sans passer par la plateforme. Page réservée au client connecté, formulaire anti-CSRF.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpbridgeAccessModuleFrontController extends ModuleFrontController
{
    public $auth = true;
    public $guestAllowed = false;
    public $ssl = false;
    protected $content_only = true;

    /** @var Ucpbridge */
    public $module;

    public function display()
    {
        return true;
    }

    public function initContent()
    {
        $e = ['UcpBridgePage', 'e'];
        $customer = $this->context->customer;
        if (!$customer->isLogged()) {
            Tools::redirect($this->context->link->getPageLink('authentication', true, null, ['back' => $this->module->accessUrl()]));
        }
        $notice = '';
        if ('POST' === $_SERVER['REQUEST_METHOD']) {
            $client = (string) Tools::getValue('client_id');
            if (!$this->module->checkFormToken($this->context->cookie, 'access|' . $client, Tools::getValue('csrf'))) {
                $notice = '<p class="err">' . $e(UcpBridgeI18n::shop('The form has expired: reload the page.')) . '</p>';
            } else {
                UcpBridgeOAuth::revokeAllFor($client, (int) $customer->id);
                $notice = '<p class="ok">' . $e(UcpBridgeI18n::shop('Access revoked: %client% can no longer act on your behalf.', ['%client%' => $client])) . '</p>';
            }
        }
        $labels = UcpBridgeOAuth::scopes();
        $items = '';
        foreach (UcpBridgeOAuth::grants((int) $customer->id) as $g) {
            $scopes = '';
            foreach ($g['scopes'] as $sc) {
                $scopes .= '<li>' . $e($labels[$sc] ?? $sc) . '</li>';
            }
            $items .= '<h2>' . $e($g['client_name']) . ' <span class="note">(' . $e($g['client_id']) . ')</span></h2>'
                . '<p class="note">' . $e(UcpBridgeI18n::shop('Linked on %date%, access valid until %expiry%', ['%date%' => $g['first_grant'], '%expiry%' => $g['expires_at']])) . '</p>'
                . '<ul>' . $scopes . '</ul>'
                . '<form method="post" data-client="' . $e($g['client_id']) . '">'
                . '<input type="hidden" name="client_id" value="' . $e($g['client_id']) . '">'
                . '<input type="hidden" name="csrf" value="' . $e($this->module->formToken($this->context->cookie, 'access|' . $g['client_id'])) . '">'
                . '<button type="submit">' . $e(UcpBridgeI18n::shop('Revoke this access')) . '</button></form>';
        }
        $body = $notice
            . '<p>' . $e(UcpBridgeI18n::shop('Assistants allowed to create orders or to view your orders on your behalf.')) . '</p>'
            . ($items ?: '<p>' . $e(UcpBridgeI18n::shop('No assistant is linked to your account.')) . '</p>')
            . '<p class="note"><a href="' . $e($this->context->link->getPageLink('my-account', true)) . '">' . $e(UcpBridgeI18n::shop('Back to my account')) . '</a></p>';
        UcpBridgePage::render(UcpBridgeI18n::shop('My agent access'), $body, 200, 'agent-access');
    }
}
