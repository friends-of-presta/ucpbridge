<?php
/**
 * Fonction de rétractation en ligne : page signée « Se rétracter de cette commande », ouverte depuis l'e-mail
 * de confirmation. Enregistre la demande (une par commande) et envoie un accusé de réception par e-mail
 * (support durable).
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpbridgeWithdrawModuleFrontController extends ModuleFrontController
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
        $order = new Order((int) Tools::getValue('order'));
        if (!$this->module->checkSigned('withdraw', ['order']) || !Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            UcpBridgePage::render(UcpBridgeI18n::shop('Invalid link'), '<p class="err">' . $e(UcpBridgeI18n::shop('Invalid or expired withdrawal link. Contact the shop.')) . '</p>', 403, 'invalid-link');
        }
        $customer = new Customer((int) $order->id_customer);
        $done = UcpBridgeProof::withdrawal((int) $order->id);
        if ($done) {
            UcpBridgePage::render(UcpBridgeI18n::shop('Withdrawal'), '<p class="ok">' . $e(UcpBridgeI18n::shop('Your withdrawal request for order %reference% was already recorded on %date%.', ['%reference%' => $order->reference, '%date%' => $done['date_add']])) . '</p>', 200,
                'withdrawal-already-recorded');
        }
        $error = '';
        if ('POST' === $_SERVER['REQUEST_METHOD']) {
            if (!$this->module->checkFormToken($this->context->cookie, 'withdraw|' . (int) $order->id, Tools::getValue('csrf'))) {
                $error = UcpBridgeI18n::shop('The form has expired: reload the page.');
            } else {
                if (UcpBridgeProof::recordWithdrawal((int) $order->id, $customer->email, Tools::getRemoteAddr())) {
                    $sent = $this->sendAcknowledgement($order, $customer);
                    if ($sent) {
                        UcpBridgeProof::markWithdrawalMailed((int) $order->id);
                    }
                }
                UcpBridgePage::render(UcpBridgeI18n::shop('Withdrawal recorded'), '<p class="ok">' . $e(UcpBridgeI18n::shop('Your withdrawal request for order %reference% is recorded.', ['%reference%' => $order->reference])) . '</p>'
                    . '<p>' . $e(UcpBridgeI18n::shop('An acknowledgement has been sent to you by e-mail at %email%. The shop will tell you how to return the items and will refund you at the latest 14 days after receiving your request.', ['%email%' => $customer->email])) . '</p>', 200,
                    'withdrawal-recorded');
            }
        }
        $rows = '';
        foreach ($order->getProducts() as $p) {
            $rows .= '<tr><td>' . (int) $p['product_quantity'] . ' × ' . $e($p['product_name']) . '</td><td class="r">'
                . $e(UcpBridgeI18n::shop('%amount% incl. tax', ['%amount%' => UcpBridgePage::euros((int) round($p['total_price_tax_incl'] * 100))])) . '</td></tr>';
        }
        $rows .= '<tr class="total"><td>' . $e(UcpBridgeI18n::shop('Total paid')) . '</td><td class="r">' . $e(UcpBridgePage::euros((int) round($order->total_paid_tax_incl * 100))) . '</td></tr>';
        $body = ($error ? '<p class="err">' . $e($error) . '</p>' : '')
            . '<p>' . $e(UcpBridgeI18n::shop('Order %reference% of %date%, in the name of %name%.', ['%reference%' => $order->reference, '%date%' => Tools::displayDate($order->date_add), '%name%' => $customer->firstname . ' ' . $customer->lastname])) . '</p>'
            . '<table>' . $rows . '</table>'
            . '<p>' . $e(UcpBridgeI18n::shop('You have 14 days from receipt of your order to withdraw, without having to give any reason. By confirming, you notify the shop of your decision to withdraw from this contract.')) . '</p>'
            . '<form method="post" action="' . $e($_SERVER['REQUEST_URI'] ?? '') . '">'
            . '<input type="hidden" name="csrf" value="' . $e($this->module->formToken($this->context->cookie, 'withdraw|' . (int) $order->id)) . '">'
            . '<button type="submit">' . $e(UcpBridgeI18n::shop('Confirm the withdrawal')) . '</button></form>';
        UcpBridgePage::render(UcpBridgeI18n::shop('Withdraw from this order'), $body, 200, 'withdrawal-form');
    }

    private function sendAcknowledgement(Order $order, Customer $customer)
    {
        $idLang = (int) $order->id_lang ?: (int) Configuration::get('PS_LANG_DEFAULT');

        // Objet dans la langue de la commande, comme le modèle mails/<iso>/ucp_withdrawal.
        $subject = UcpBridgeI18n::shop('Acknowledgement of your withdrawal request', [], UcpBridgeI18n::localeOf($idLang));

        return (bool) Mail::send($idLang, 'ucp_withdrawal', $subject,
            [
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{order_name}' => $order->reference,
                '{withdrawal_date}' => Tools::displayDate(date('Y-m-d H:i:s'), true),
            ],
            $customer->email, $customer->firstname . ' ' . $customer->lastname, null, null, null, null,
            _PS_MODULE_DIR_ . $this->module->name . '/mails/', false, (int) $order->id_shop);
    }
}
