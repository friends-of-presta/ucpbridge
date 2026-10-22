<?php
/**
 * Relecture des CGV chez le marchand (option) : page signée ouverte par le continue_url d'une session
 * en requires_buyer_review. Récapitulatif, CGV, case « J'ai lu et j'accepte les CGV », puis enregistrement
 * de l'acceptation (date, version des CGV, IP) : la session redevient finalisable par l'agent.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpbridgeTermsModuleFrontController extends ModuleFrontController
{
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
        if (!$this->module->checkSigned('terms', ['session'])) {
            UcpBridgePage::render(UcpBridgeI18n::shop('Invalid link'), '<p class="err">' . $e(UcpBridgeI18n::shop('Invalid or expired link: go back to the assistant to get a new link.')) . '</p>', 403, 'invalid-link');
        }
        $sid = (string) Tools::getValue('session');
        $svc = new UcpBridgeCart($this->context, $this->module);
        $s = $svc->load($sid, 'checkout');
        if (!$s || UcpBridgeCart::isTerminal($s['status']) || UcpBridgeCart::isBusy($s['status'])) {
            UcpBridgePage::render(UcpBridgeI18n::shop('Session ended'), '<p class="err">' . $e(UcpBridgeI18n::shop('This order has already been placed or canceled.')) . '</p>', 410, 'session-ended');
        }
        $error = '';
        if ('POST' === $_SERVER['REQUEST_METHOD']) {
            if (!$this->module->checkFormToken($this->context->cookie, 'terms|' . $sid, Tools::getValue('csrf'))) {
                $error = UcpBridgeI18n::shop('The form has expired: reload the page.');
            } elseif (!Tools::getValue('accept_terms')) {
                $error = UcpBridgeI18n::shop('Please tick the box to accept the terms of sale.');
            } else {
                $svc->acceptTerms($s, Tools::getRemoteAddr(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                UcpBridgePage::render(UcpBridgeI18n::shop('Thank you'), '<p class="ok">' . $e(UcpBridgeI18n::shop('Your acceptance of the terms of sale is recorded.')) . '</p>'
                    . '<p>' . $e(UcpBridgeI18n::shop('You can go back to your assistant to complete the order.')) . '</p>', 200, 'terms-accepted');
            }
        } elseif (!empty($s['payload']['terms_acceptance'])) {
            UcpBridgePage::render(UcpBridgeI18n::shop('Already accepted'), '<p class="ok">' . $e(UcpBridgeI18n::shop('Your acceptance of the terms of sale is already recorded. Go back to your assistant to complete the order.')) . '</p>', 200, 'terms-already-accepted');
        }

        $doc = $svc->checkoutDoc($s);
        $terms = $this->module->termsInfo();
        $rows = '';
        foreach ($doc['line_items'] as $li) {
            $rows .= '<tr><td>' . (int) $li['quantity'] . ' × ' . $e($li['item']['title']) . '</td><td class="r">'
                . $e(UcpBridgeI18n::shop('%amount% incl. tax', ['%amount%' => UcpBridgePage::euros($li['item']['price'] * $li['quantity'])])) . '</td></tr>';
        }
        foreach ($doc['totals'] as $t) {
            $rows .= '<tr class="' . ('total' === $t['type'] ? 'total' : '') . '"><td>' . $e($t['display_text'] ?? $t['type']) . '</td><td class="r">'
                . $e(UcpBridgePage::euros($t['amount'])) . '</td></tr>';
        }
        $links = '';
        foreach (array_unique(array_column($doc['links'], 'title', 'url')) as $title) {
            $links .= '<li>' . $e($title) . '</li>';
        }
        $action = $e($_SERVER['REQUEST_URI'] ?? '');
        $body = ($error ? '<p class="err">' . $e($error) . '</p>' : '')
            . '<h2>' . $e(UcpBridgeI18n::shop('Summary of your order')) . '</h2><table>' . $rows . '</table>'
            . '<h2>' . $e($terms['title']) . '</h2>'
            // Contenu de la page CMS saisi par le marchand dans son back-office (même rendu que la boutique).
            . '<div class="terms">' . $terms['content'] . '</div>'
            . '<p class="note">' . $e(UcpBridgeI18n::shop('Version %version%. Applicable documents:', ['%version%' => $terms['version']])) . ' </p><ul class="note">' . $links . '</ul>'
            . '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="csrf" value="' . $e($this->module->formToken($this->context->cookie, 'terms|' . $sid)) . '">'
            . '<label class="check"><input type="checkbox" name="accept_terms" value="1"> ' . $e(UcpBridgeI18n::shop('I have read and accept the terms of sale')) . '</label>'
            . '<button type="submit">' . $e(UcpBridgeI18n::shop('Record my acceptance')) . '</button></form>';
        UcpBridgePage::render(UcpBridgeI18n::shop('Terms of sale'), $body, 200, 'terms-review');
    }
}
