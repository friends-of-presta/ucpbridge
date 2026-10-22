<?php
/**
 * Jeu de données de la démo ucpbridge (boutique 8092), à lancer dans le conteneur :
 *   php /tmp/seed-demo.php
 * - références de déclinaison uniques sur le t-shirt colibri (demo_1-M-BLANC, ...)
 * - impact de prix +2 € HT sur la taille XL, prix spécifique -30 % sur L Noir (remplace la remise produit de -20 %)
 * - stock à 0 sur S Noir (contrôle du stock PAR déclinaison)
 * - e-mails envoyés au capteur local mailpit (SMTP mailpit:1025), Suisse active (hors UE), secret de simulation
 * - règles panier : BIENVENUE10 (-10 %), EXPIRE2025 (expiré), FIDELE5 (-5 € réservé au client de démo),
 *   livraison offerte automatique (sans code) dès 50 € TTC
 * - Google Pay de test via stripe-mock (clé factice sk_test_contrib), client OAuth google-agent-test
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
define('_PS_ADMIN_DIR_', '/var/www/html/admin');
require '/var/www/html/config/config.inc.php';
Context::getContext()->employee = new Employee(1);
Module::getInstanceByName('ucpbridge'); // charge les classes du module (UcpBridgeOAuth)

$idProduct = 1;
$product = new Product($idProduct, false, 1);
$codes = ['Blanc' => 'BLANC', 'Noir' => 'NOIR'];
foreach ($product->getAttributeCombinations(1) as $row) {
    $byPa[(int) $row['id_product_attribute']][$row['group_name']] = $row['attribute_name'];
}
foreach ($byPa as $idPa => $opt) {
    $ref = 'demo_1-' . $opt['Taille'] . '-' . ($codes[$opt['Couleur']] ?? strtoupper($opt['Couleur']));
    $c = new Combination($idPa);
    $c->reference = $ref;
    $c->price = 'XL' === $opt['Taille'] ? 2.0 : 0.0;
    $c->update();
    $stock = ('S' === $opt['Taille'] && 'Noir' === $opt['Couleur']) ? 0 : 300;
    StockAvailable::setQuantity($idProduct, $idPa, $stock);
    if ('L' === $opt['Taille'] && 'Noir' === $opt['Couleur']) {
        Db::getInstance()->delete('specific_price', 'id_product = 1 AND id_product_attribute = ' . (int) $idPa);
        $sp = new SpecificPrice();
        $sp->id_product = $idProduct;
        $sp->id_product_attribute = $idPa;
        $sp->id_shop = 0;
        $sp->id_currency = 0;
        $sp->id_country = 0;
        $sp->id_group = 0;
        $sp->id_customer = 0;
        $sp->price = -1;
        $sp->from_quantity = 1;
        $sp->reduction = 0.30;
        $sp->reduction_tax = 1;
        $sp->reduction_type = 'percentage';
        $sp->from = '0000-00-00 00:00:00';
        $sp->to = '0000-00-00 00:00:00';
        $sp->add();
    }
    printf("%d  %-18s stock %d%s\n", $idPa, $ref, $stock, 'XL' === $opt['Taille'] ? '  (+2 € HT)' : '');
}
// TVA : l'installation automatique de l'image ne crée aucune règle de taxe. FR 20 %, BE 21 %.
$idGroup = (int) Db::getInstance()->getValue('SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE name = \'TVA standard (démo UCP)\' AND deleted = 0');
if (!$idGroup) {
    $g = new TaxRulesGroup();
    $g->name = 'TVA standard (démo UCP)';
    $g->active = 1;
    $g->add();
    $idGroup = (int) $g->id;
    foreach (['FR' => 20, 'BE' => 21] as $iso => $rate) {
        $t = new Tax();
        $t->name = [1 => 'TVA ' . $iso . ' ' . $rate . ' %'];
        $t->rate = $rate;
        $t->active = 1;
        $t->add();
        $r = new TaxRule();
        $r->id_tax_rules_group = $idGroup;
        $r->id_country = (int) Country::getByIso($iso);
        $r->id_tax = (int) $t->id;
        $r->behavior = 0;
        $r->zipcode_from = 0;
        $r->zipcode_to = 0;
        $r->add();
    }
}
Db::getInstance()->update('product', ['id_tax_rules_group' => $idGroup]);
Db::getInstance()->update('product_shop', ['id_tax_rules_group' => $idGroup]);
// Colonne carrier.id_tax_rules_group : présente en PrestaShop 9 seulement (PrestaShop 8 ne connaît que la table
// carrier_tax_rules_group_shop, remplie juste après).
if (Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'carrier` LIKE \'id_tax_rules_group\'')) {
    Db::getInstance()->update('carrier', ['id_tax_rules_group' => $idGroup], 'deleted = 0');
}
Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'carrier_tax_rules_group_shop');
foreach (Db::getInstance()->executeS('SELECT id_carrier FROM ' . _DB_PREFIX_ . 'carrier WHERE deleted = 0') as $c) {
    Db::getInstance()->insert('carrier_tax_rules_group_shop', ['id_carrier' => (int) $c['id_carrier'], 'id_tax_rules_group' => $idGroup, 'id_shop' => 1]);
}
// Belgique livrable, et deux transporteurs de démo actifs en plus de « My carrier ».
Db::getInstance()->update('country', ['active' => 1], 'iso_code = \'BE\'');
Db::getInstance()->update('carrier', ['active' => 1], 'id_carrier IN (3, 4)');
echo "Règles de TVA : groupe $idGroup (FR 20 %, BE 21 %)\n";

// E-mails : SMTP vers le capteur local mailpit (docker compose), jamais vers Internet.
Configuration::updateValue('PS_MAIL_METHOD', 2);
Configuration::updateValue('PS_MAIL_SERVER', 'mailpit');
Configuration::updateValue('PS_MAIL_SMTP_PORT', 1025);
Configuration::updateValue('PS_MAIL_SMTP_ENCRYPTION', 'off');
Configuration::updateValue('PS_MAIL_USER', '');
Configuration::updateValue('PS_MAIL_PASSWD', '');
Configuration::updateValue('PS_MAIL_TYPE', 3);
// Suisse active (hors Union européenne) : la mention de rétractation ne doit pas s'y afficher.
Db::getInstance()->update('country', ['active' => 1], 'iso_code = \'CH\'');
Configuration::updateValue('UCPBRIDGE_MOCK_SECRET', 'simulation-contrib-2026');
echo "Clé API (plateforme par défaut) : " . (UcpBridgeApiKeys::all()[UcpBridgeApiKeys::DEFAULT_ID]['key'] ?? '(aucune)') . "\n";

// ---------- Règles panier de démonstration (codes promo UCP) ----------
foreach (Db::getInstance()->executeS('SELECT r.id_cart_rule FROM ' . _DB_PREFIX_ . 'cart_rule r JOIN ' . _DB_PREFIX_ . 'cart_rule_lang l
    ON l.id_cart_rule = r.id_cart_rule AND l.id_lang = 1 WHERE r.code IN (\'BIENVENUE10\', \'EXPIRE2025\', \'FIDELE5\')
    OR l.name = \'Livraison offerte dès 50 € (démo UCP)\'') as $row) {
    (new CartRule((int) $row['id_cart_rule']))->delete();
}
function demo_rule($code, $name, array $f)
{
    $r = new CartRule();
    $r->code = $code;
    $r->name = [1 => $name];
    foreach (Language::getLanguages(false) as $l) {
        $r->name[(int) $l['id_lang']] = $name;
    }
    $r->date_from = date('Y-m-d H:i:s', strtotime('-1 day'));
    $r->date_to = date('Y-m-d H:i:s', strtotime('+1 year'));
    $r->quantity = 10000;
    $r->quantity_per_user = 10000;
    $r->active = 1;
    $r->highlight = 0;
    $r->partial_use = 0;
    $r->cart_rule_restriction = 0;
    foreach ($f as $k => $v) {
        $r->$k = $v;
    }
    $r->add();
    printf("Règle panier %-12s %s (id %d)\n", $code ?: '(auto)', $name, $r->id);
}
demo_rule('BIENVENUE10', 'Bienvenue -10 %', ['reduction_percent' => 10]);
demo_rule('EXPIRE2025', 'Soldes 2025 -20 % (expiré)', ['reduction_percent' => 20,
    'date_from' => '2025-01-01 00:00:00', 'date_to' => '2025-12-31 23:59:59']);
demo_rule('FIDELE5', 'Fidélité -5 € (réservé au client de démo)', ['reduction_amount' => 5, 'reduction_tax' => 1,
    'reduction_currency' => 1, 'id_customer' => 2]);
demo_rule('', 'Livraison offerte dès 50 € (démo UCP)', ['free_shipping' => 1, 'minimum_amount' => 50,
    'minimum_amount_tax' => 1, 'minimum_amount_currency' => 1, 'minimum_amount_shipping' => 0]);

// ---------- Google Pay (TEST) via Stripe, faux Stripe local ----------
Configuration::updateValue('UCPBRIDGE_STRIPE_KEY', 'sk_test_contrib');
Configuration::updateValue('UCPBRIDGE_STRIPE_PK', 'pk_test_contrib');
Configuration::updateValue('UCPBRIDGE_STRIPE_API_BASE', 'http://stripe-mock:12111');
Configuration::updateValue('UCPBRIDGE_GPAY_MERCHANT_ID', 'TEST-CONTRIB-410');
echo "Google Pay TEST : passerelle stripe -> " . Configuration::get('UCPBRIDGE_STRIPE_API_BASE') . "\n";

// ---------- Client OAuth de test (identity linking) ----------
// Secret de TEST du banc local, repris par agent_bridge_v2.py (jamais un secret réel).
define('DEMO_OAUTH_SECRET', 'contrib-test-oauth-secret-2026');
UcpBridgeOAuth::registerClient('google-agent-test', 'Assistant Google (test Contrib.)',
    ['http://localhost:8765/callback'], DEMO_OAUTH_SECRET);
echo "Client OAuth google-agent-test (client_secret_basic), redirect_uri http://localhost:8765/callback\n";

