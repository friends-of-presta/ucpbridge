<?php
/**
 * Adaptateur Stripe pour le gestionnaire Google Pay (com.google.pay, tokenisation PAYMENT_GATEWAY).
 * Google Pay renvoie, pour la passerelle « stripe », un jeton Stripe sérialisé en JSON
 * ({"id": "tok_...", "object": "token", "card": {...}}). L'adaptateur en extrait l'id et
 * débite par l'API Charges (source = jeton), avec Idempotency-Key transmise à Stripe.
 *
 * URL de base configurable (UCPBRIDGE_STRIPE_API_BASE) : https://api.stripe.com par défaut,
 * http://stripe-mock:12111 sur le banc. Le prototype refuse les clés live (sk_live_).
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeStripeProvider implements UcpBridgePaymentProvider
{
    const HANDLER = 'com.google.pay';
    const HANDLER_ID = 'gpay_stripe';
    const GPAY_VERSION = '2026-01-23';
    const DEFAULT_BASE = 'https://api.stripe.com';

    public function getName()
    {
        return 'stripe';
    }

    private function key()
    {
        return (string) Configuration::get('UCPBRIDGE_STRIPE_KEY');
    }

    private function base()
    {
        $b = rtrim((string) Configuration::get('UCPBRIDGE_STRIPE_API_BASE'), '/');

        return '' !== $b ? $b : self::DEFAULT_BASE;
    }

    public function isTestMode()
    {
        return 0 === strpos($this->key(), 'sk_test_');
    }

    public function handlers()
    {
        if (!$this->isTestMode()) {
            return []; // pas de clé, ou clé live : gestionnaire non annoncé dans ce prototype
        }
        $shop = Context::getContext()->shop;
        $origin = rtrim(Context::getContext()->link->getBaseLink(), '/');

        return [self::HANDLER => [[
            'id' => self::HANDLER_ID,
            'version' => self::GPAY_VERSION,
            'spec' => 'https://pay.google.com/gp/p/ucp/' . self::GPAY_VERSION . '/',
            'schema' => 'https://pay.google.com/gp/p/ucp/' . self::GPAY_VERSION . '/schemas/config.json',
            'config' => [
                'api_version' => 2,
                'api_version_minor' => 0,
                'environment' => 'TEST',
                'merchant_info' => [
                    'merchant_name' => (string) $shop->name,
                    'merchant_id' => (string) Configuration::get('UCPBRIDGE_GPAY_MERCHANT_ID'),
                    'merchant_origin' => $origin,
                ],
                'allowed_payment_methods' => [[
                    'type' => 'CARD',
                    'parameters' => [
                        'allowed_auth_methods' => ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                        'allowed_card_networks' => ['VISA', 'MASTERCARD', 'AMEX'],
                    ],
                    'tokenization_specification' => [
                        'type' => 'PAYMENT_GATEWAY',
                        'parameters' => [
                            'gateway' => 'stripe',
                            'stripe:version' => '2018-10-31',
                            'stripe:publishableKey' => (string) Configuration::get('UCPBRIDGE_STRIPE_PK'),
                        ],
                    ],
                ]],
            ],
        ]]];
    }

    public function supports($handlerName, $handlerId)
    {
        return self::HANDLER === $handlerName && self::HANDLER_ID === $handlerId;
    }

    /** Id du jeton Stripe : JSON Google Pay (passerelle stripe) ou id brut tok_... */
    public static function tokenId($raw)
    {
        $raw = trim((string) $raw);
        if ('' !== $raw && '{' === $raw[0]) {
            $j = json_decode($raw, true);
            $raw = is_array($j) ? (string) ($j['id'] ?? '') : '';
        }

        return preg_match('/^tok_[A-Za-z0-9_]{1,250}$/', $raw) ? $raw : '';
    }

    public function charge(array $credential, $amount, $currency, array $context)
    {
        if (!$this->isTestMode()) {
            return UcpBridgePaymentResult::fail(UcpBridgeI18n::api('Google Pay disabled: Stripe test key missing'));
        }
        if ('PAYMENT_GATEWAY' !== ($credential['type'] ?? '')) {
            return UcpBridgePaymentResult::fail('credential.type PAYMENT_GATEWAY attendu pour com.google.pay');
        }
        $tok = self::tokenId($credential['token'] ?? '');
        if ('' === $tok) {
            return UcpBridgePaymentResult::fail('Jeton Google Pay illisible (jeton Stripe tok_... attendu)');
        }
        $params = [
            'amount' => (int) $amount,
            'currency' => Tools::strtolower((string) $currency),
            'source' => $tok,
            'description' => 'Commande UCP ' . ($context['session_id'] ?? ''),
            'metadata[ucp_session]' => (string) ($context['session_id'] ?? ''),
            'metadata[id_cart]' => (string) ($context['id_cart'] ?? ''),
        ];
        list($code, $doc, $err, $errno) = $this->post('/v1/charges', $params, 'ucpbridge-' . ($context['idempotency_key'] ?? ($context['session_id'] ?? '')));
        if ($err) {
            // Détail technique (hôte, erreur réseau) au journal de la boutique, message générique à l'agent.
            PrestaShopLogger::addLog('ucpbridge Stripe injoignable : ' . $err, 3);
            if (self::neverSent($errno)) {
                return UcpBridgePaymentResult::fail(UcpBridgeI18n::api('Payment gateway unreachable, try again later'));
            }

            // Requête peut-être reçue par Stripe (délai dépassé, connexion coupée) : débit possible.
            return UcpBridgePaymentResult::unknown(UcpBridgeI18n::api('Payment gateway response lost: charge to be checked'));
        }
        if ($code >= 500 && 'charge' !== ($doc['object'] ?? '')) {
            // Erreur serveur Stripe : le débit a pu avoir lieu (https://docs.stripe.com/error-low-level#server-errors).
            PrestaShopLogger::addLog('ucpbridge Stripe erreur ' . $code . ' sans charge : ' . (string) ($doc['error']['message'] ?? ''), 3);

            return UcpBridgePaymentResult::unknown(UcpBridgeI18n::api('Payment gateway error (HTTP %code%): charge to be checked', ['%code%' => $code]), $doc);
        }
        if (isset($doc['error'])) {
            $e = $doc['error'];
            if ('authentication_required' === ($e['code'] ?? '') || 'authentication_required' === ($e['decline_code'] ?? '')) {
                // 3-D Secure exigé : l'acheteur doit s'authentifier, la caisse passe en requires_escalation.
                return UcpBridgePaymentResult::action((string) ($e['charge'] ?? ''), (string) ($context['continue_url'] ?? ''), $doc);
            }

            return UcpBridgePaymentResult::fail(UcpBridgeI18n::api('Payment refused: %reason%', ['%reason%' => (string) ($e['message'] ?? UcpBridgeI18n::api('Stripe error'))]), $doc);
        }
        if ($code >= 300 || 'charge' !== ($doc['object'] ?? '') || 'succeeded' !== ($doc['status'] ?? '') || empty($doc['paid'])) {
            return UcpBridgePaymentResult::fail(UcpBridgeI18n::api('Payment not confirmed by the gateway (status %status%)', ['%status%' => $doc['status'] ?? $code]), (array) $doc);
        }
        if ((int) $doc['amount'] !== (int) $amount) {
            return UcpBridgePaymentResult::fail(UcpBridgeI18n::api('Inconsistent charged amount'), $doc);
        }

        return UcpBridgePaymentResult::ok((string) $doc['id'], UcpBridgeI18n::api('Google Pay (test)'), $doc);
    }

    /** Remboursement par l'API Refunds (charge = référence ch_...), Idempotency-Key liée au débit. */
    public function refund($transactionId, $amount, $currency, array $context)
    {
        if (!$this->isTestMode()) {
            return UcpBridgePaymentResult::fail(UcpBridgeI18n::api('Stripe test key missing: refund to be made in the Stripe dashboard'));
        }
        list($code, $doc, $err) = $this->post('/v1/refunds', ['charge' => (string) $transactionId, 'amount' => (int) $amount,
            'metadata[ucp_session]' => (string) ($context['session_id'] ?? '')], 'ucpbridge-refund-' . $transactionId);
        if ($err) {
            PrestaShopLogger::addLog('ucpbridge Stripe injoignable (remboursement) : ' . $err, 3);

            return UcpBridgePaymentResult::fail('Passerelle de paiement injoignable');
        }
        if ($code >= 300 || 'refund' !== ($doc['object'] ?? '') || !in_array($doc['status'] ?? '', ['succeeded', 'pending'], true)) {
            return UcpBridgePaymentResult::fail(UcpBridgeI18n::api('Refund refused: %reason%', ['%reason%' => (string) ($doc['error']['message'] ?? ($doc['status'] ?? $code))]), (array) $doc);
        }

        return UcpBridgePaymentResult::ok((string) $doc['id'], UcpBridgeI18n::api('Stripe refund'), $doc);
    }

    /**
     * Erreur cURL survenue avant tout envoi de la requête (résolution DNS, connexion refusée, proxy) : Stripe n'a rien
     * reçu, aucun débit possible. Toute autre erreur (délai dépassé, connexion coupée, réponse vide) laisse le
     * résultat inconnu.
     */
    public static function neverSent($errno)
    {
        return in_array((int) $errno, [5, 6, 7], true); // COULDNT_RESOLVE_PROXY, COULDNT_RESOLVE_HOST, COULDNT_CONNECT
    }

    private function post($path, array $params, $idemKey)
    {
        $ch = curl_init($this->base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERPWD => $this->key() . ':',
            CURLOPT_HTTPHEADER => ['Idempotency-Key: ' . substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $idemKey), 0, 255), 'Stripe-Version: 2024-06-20'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $body = curl_exec($ch);
        $err = false === $body ? curl_error($ch) : '';
        $errno = false === $body ? curl_errno($ch) : 0;
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $doc = is_string($body) ? json_decode($body, true) : null;

        return [$code, is_array($doc) ? $doc : [], $err, $errno];
    }
}
