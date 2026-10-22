<?php
/**
 * Amorce des tests unitaires : logique pure du module, sans PrestaShop.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
define('_PS_VERSION_', 'tests');
foreach (['UcpBridgeItemRef', 'UcpBridgeTotals', 'UcpBridgeIdempotency', 'UcpBridgeSigner', 'UcpBridgePkce',
    'UcpBridgeConsent', 'UcpBridgeLegal', 'UcpBridgeMcp', 'UcpBridgeSchema', 'UcpBridgeExport'] as $c) {
    if (is_file($f = __DIR__ . '/../classes/' . $c . '.php')) {
        require_once $f;
    }
}
