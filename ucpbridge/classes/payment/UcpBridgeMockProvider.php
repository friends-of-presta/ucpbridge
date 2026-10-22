<?php
/**
 * Paiement de test du banc (fr.410gone.ucpbridge.mock) : actif seulement si un secret
 * de simulation est configuré, accepté seulement si credential.token = ce secret.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeMockProvider implements UcpBridgePaymentProvider
{
    const HANDLER = 'fr.410gone.ucpbridge.mock';
    const HANDLER_ID = 'mock_contrib';

    public function getName()
    {
        return 'mock';
    }

    public function handlers()
    {
        if ('' === (string) Configuration::get('UCPBRIDGE_MOCK_SECRET')) {
            return [];
        }

        return [self::HANDLER => [[
            'id' => self::HANDLER_ID,
            'version' => Ucpbridge::UCP_VERSION,
            'available_instruments' => [['type' => 'mock']],
            'config' => ['mode' => 'simulation', 'note' => 'Paiement de test : credential.token = secret de simulation'],
        ]]];
    }

    public function supports($handlerName, $handlerId)
    {
        return self::HANDLER === $handlerName && self::HANDLER_ID === $handlerId;
    }

    public function charge(array $credential, $amount, $currency, array $context)
    {
        $secret = (string) Configuration::get('UCPBRIDGE_MOCK_SECRET');
        if ('' === $secret || !hash_equals($secret, (string) ($credential['token'] ?? ''))) {
            return UcpBridgePaymentResult::fail(UcpBridgeI18n::api('Test payment refused (incorrect simulation token)'));
        }

        // Référence propre à chaque débit : le journal des paiements compte les débits réels.
        return UcpBridgePaymentResult::ok('mock_' . bin2hex(random_bytes(8)), UcpBridgeI18n::api('UCP test payment'));
    }

    public function refund($transactionId, $amount, $currency, array $context)
    {
        return UcpBridgePaymentResult::ok('mockre_' . bin2hex(random_bytes(8)), UcpBridgeI18n::api('Test refund'));
    }
}
