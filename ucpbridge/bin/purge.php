<?php
/**
 * Purge RGPD des données du module selon les durées de conservation de la configuration (sessions et paniers,
 * clients invités sans commande créés par le module, codes et jetons OAuth, journal des paiements, journal de preuve
 * et rétractations, clés d'idempotence). Ligne de commande uniquement, à planifier (tâche cron quotidienne) :
 *
 *   php modules/ucpbridge/bin/purge.php [--dry-run]
 *
 * --dry-run : simulation, compte ce qui serait supprimé sans rien supprimer. Les commandes passées ne sont jamais
 * touchées. Compte rendu : une ligne par catégorie (aussi consigné au journal PrestaShop). Code de sortie 0 ; 2 (rien
 * n'est supprimé) si la durée du journal des paiements ou des preuves est sous son plancher légal (3650 jours, ou 0).
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if ('cli' !== PHP_SAPI) {
    http_response_code(404);
    exit;
}
$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
$root = dirname(__DIR__, 3);
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $root . '/admin');
}
require $root . '/config/config.inc.php';

$module = Module::getInstanceByName('ucpbridge');
if (!$module || !Module::isInstalled('ucpbridge')) {
    fwrite(STDERR, "module ucpbridge non installé\n");
    exit(1);
}
$ctx = Context::getContext();
$ctx->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
$ctx->link = new Link();
$ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));

// Durée légale trop courte (écrite hors du back-office) : refus, rien n'est supprimé.
if ($bad = UcpBridgePurge::refusedDurations()) {
    fwrite(STDERR, UcpBridgeRetention::purgeRefusedMessage($bad) . "\n");
    exit(2);
}
echo $dryRun ? "Purge RGPD ucpbridge : simulation, rien n'est supprimé\n" : "Purge RGPD ucpbridge : compte rendu\n";
$durations = [];
foreach (UcpBridgePurge::durations() as $cat => $days) {
    $durations[] = $cat . ' ' . ($days ? $days . ' j' : 'jamais');
}
echo 'Durées de conservation : ', implode(', ', $durations), "\n";
foreach (UcpBridgeRetention::summary((new UcpBridgePurge($dryRun))->run(), $dryRun) as $line) {
    echo $line, "\n";
}
exit(0);
