<?php
/**
 * Rapprochement des sessions de caisse UCP bloquées (processus PHP tué, erreur fatale ou délai d'exécution dépassé
 * pendant la finalisation). Ligne de commande uniquement, à lancer à la main ou en tâche planifiée :
 *
 *   php modules/ucpbridge/bin/reconcile.php [--minutes=15]
 *
 * Pour chaque session réservée depuis plus de N minutes : commande trouvée sur le panier = session terminée par
 * elle ; débit réussi sans commande = remboursement par l'adaptateur et incident (gravité 4) ; débit engagé sans
 * résultat connu = incident à vérifier chez le prestataire ; rien d'engagé = session rendue finalisable.
 * Code de sortie 0, une ligne par session traitée.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if ('cli' !== PHP_SAPI) {
    http_response_code(404);
    exit;
}
$minutes = 15;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--minutes=(\d+)$/', $arg, $m)) {
        $minutes = max(1, (int) $m[1]);
    }
}
$root = dirname(__DIR__, 3);
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $root . '/admin');
}
require $root . '/config/config.inc.php';

$module = Module::getInstanceByName('ucpbridge');
if (!$module || !Module::isInstalled('ucpbridge')) {
    fwrite(STDERR, "ucpbridge module not installed\n");
    exit(1);
}
$ctx = Context::getContext();
$ctx->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
$ctx->link = new Link();
$ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$ctx->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$ctx->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));

$done = (new UcpBridgeCart($ctx, $module))->reconcile($minutes);
foreach ($done as $d) {
    // Code stable entre crochets : à lire par les scripts, le libellé suit la langue de la boutique.
    echo $d[0], ' : ', $d[1], ' [', $d[2], "]\n";
}
echo UcpBridgeI18n::admin('%count% session(s) processed, threshold %minutes% minute(s)', ['%count%' => count($done), '%minutes%' => $minutes]), "\n";
exit(0);
