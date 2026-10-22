"""Issue #2 : un débit réussi n'est jamais oublié parce que l'écriture du journal des paiements échoue.

Le journal (ps_ucpbridge_payment) est rendu indisponible pour une seule session par un déclencheur MySQL qui refuse
l'insertion du débit. En mode debug (_PS_MODE_DEV_), PrestaShop transforme l'erreur SQL en exception
(PrestaShopDatabaseException) : c'est le cas décrit dans l'issue. Hors debug, l'insertion échoue en silence.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import json
import re
import subprocess
from contextlib import contextmanager

from conftest import MYSQL, NOYAU_PHP, PS, sql, sql_val
from test_04_relecture import complete, id_cart
from test_05_relecture2 import commandes, journal, statut


@contextmanager
def journal_indisponible(sid):
    """Déclencheur qui refuse l'écriture du débit de cette session dans le journal des paiements."""
    nom = "ucp_test_journal_" + re.sub(r"[^a-z0-9]", "", sid)
    ddl = (f"DROP TRIGGER IF EXISTS {nom};\nDELIMITER //\nCREATE TRIGGER {nom} BEFORE INSERT ON ps_ucpbridge_payment FOR EACH ROW "
           f"BEGIN IF NEW.operation = 'charge' AND NEW.session_id = '{sid}' THEN "
           "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal indisponible (simulé)'; END IF; END//\nDELIMITER ;\n")
    r = subprocess.run(MYSQL[:2] + ["-i"] + MYSQL[2:-1], input=ddl, capture_output=True, text=True)
    assert r.returncode == 0, r.stderr
    try:
        yield
    finally:
        sql(f"DROP TRIGGER IF EXISTS {nom}")


# Finalisation dans le conteneur, PrestaShop en mode debug (les erreurs SQL deviennent des exceptions).
SONDE = r"""<?php define('_PS_MODE_DEV_', true); define('_PS_ADMIN_DIR_', '/var/www/html/admin');
require '/var/www/html/config/config.inc.php';
NOYAU
error_reporting(E_ERROR); ini_set('display_errors', 'off');
$m = Module::getInstanceByName('ucpbridge');
$ctx = Context::getContext();
$ctx->link = new Link(); $ctx->shop = new Shop(1); $ctx->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT')); $ctx->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
$svc = new UcpBridgeCart($ctx, $m);
$svc->setCaller('platform');
$s = $svc->load(SID, 'checkout');
try {
    list($code, $doc) = $svc->completeCheckout($s, ['payment' => ['instruments' => [INSTR]]]);
    echo "\nRESULT " . json_encode(['code' => $code, 'status' => $doc['status'] ?? null]);
} catch (Throwable $e) {
    echo "\nEXCEPTION " . get_class($e) . ' ' . $e->getMessage();
}
"""


def finaliser_en_debug(boutique, sid):
    code = (SONDE.replace("NOYAU", NOYAU_PHP.strip()).replace("SID", json.dumps(sid))
            .replace("INSTR", "json_decode(" + json.dumps(json.dumps(boutique.instrument_mock())) + ", true)"))
    r = subprocess.run(["docker", "exec", "-i", "-u", "www-data", PS, "php"], input=code, capture_output=True, text=True)
    return r.stdout.strip()


def alerte_journal(sid):
    return sql_val(f"SELECT CONCAT(severity, ' ', message) FROM ps_log WHERE message LIKE '%journal des paiements%{sid}%' "
                   "ORDER BY id_log DESC LIMIT 1")


def test_exception_du_journal_apres_debit_ne_perd_pas_le_debit(boutique):
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    with journal_indisponible(sid):
        out = finaliser_en_debug(boutique, sid)
    assert "RESULT" in out, out[-800:]
    res = json.loads(out.split("RESULT ", 1)[1].splitlines()[0])
    # Le débit a réussi : la commande est créée malgré le journal indisponible, rien n'est remboursé.
    assert res == {"code": 200, "status": "completed"}, res
    assert statut(sid) == "completed"
    assert len(commandes(cart)) == 1
    assert not [j for j in journal(sid) if j[0] == "refund"], journal(sid)
    # L'échec du journal est signalé au marchand, avec la référence du débit.
    alerte = alerte_journal(sid)
    assert alerte.startswith("3 ") and "mock_" in alerte, alerte


def test_journal_en_echec_silencieux_signale(boutique):
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    with journal_indisponible(sid):
        r = complete(boutique, sid)
    assert r.code == 200 and r.doc["status"] == "completed", r.texte[:500]
    assert len(commandes(cart)) == 1
    assert "mock_" in alerte_journal(sid)
