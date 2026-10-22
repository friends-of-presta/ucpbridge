"""Issue #1 : un débit au résultat inconnu (erreur 5xx, connexion coupée, exception de l'adaptateur) n'est jamais
traité comme un refus. La session reste réservée avec la trace du débit engagé, et le rapprochement la classe en
« débit incertain, à vérifier chez le prestataire ».

Faux Stripe : petit serveur PHP intégré lancé dans le conteneur de la boutique (127.0.0.1:12199), qui répond selon le
mode écrit dans /tmp/faux-stripe-mode. L'URL de l'API Stripe (UCPBRIDGE_STRIPE_API_BASE, déjà réglable par script pour
le banc) pointe vers lui le temps du test, puis revient au stripe-mock du banc.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import json
import re
import subprocess
import time

import pytest

from conftest import PS, config, jeton_gpay, set_config, sql, sql_val
from test_04_relecture import complete, id_cart
from test_05_relecture2 import commandes, php_noyau, rapprocher, statut, vieillir

PORT = 12199
ROUTEUR = r"""<?php
$mode = trim((string) @file_get_contents('/tmp/faux-stripe-mode'));
header('Content-Type: application/json');
if ('coupure' === $mode) {
    // Requête reçue, réponse jamais envoyée : le serveur meurt (connexion coupée côté client).
    posix_kill(getmypid(), 9);
}
if ('502' === $mode) {
    http_response_code(502);
    return true;
}
http_response_code(500);
echo json_encode(['error' => ['type' => 'api_error', 'message' => 'Erreur interne simulée']]);
return true;
"""


def docker_sh(cmd):
    return subprocess.run(["docker", "exec", PS, "sh", "-c", cmd], capture_output=True, text=True)


def demarrer_faux_stripe(mode):
    docker_sh("[ -f /tmp/faux-stripe.pid ] && kill $(cat /tmp/faux-stripe.pid) 2>/dev/null; rm -f /tmp/faux-stripe.pid")
    subprocess.run(["docker", "exec", "-i", PS, "sh", "-c", "cat > /tmp/faux-stripe.php"], input=ROUTEUR, text=True)
    docker_sh(f"printf %s {mode} > /tmp/faux-stripe-mode && chmod 644 /tmp/faux-stripe.php /tmp/faux-stripe-mode")
    docker_sh(f"nohup php -S 127.0.0.1:{PORT} /tmp/faux-stripe.php > /tmp/faux-stripe.log 2>&1 & echo $! > /tmp/faux-stripe.pid")
    for _ in range(50):
        if docker_sh(f"php -r 'exit(@fsockopen(\"127.0.0.1\", {PORT}) ? 0 : 1);'").returncode == 0:
            return
        time.sleep(0.1)
    raise AssertionError("faux Stripe non démarré")


@pytest.fixture
def faux_stripe():
    ancien = config("UCPBRIDGE_STRIPE_API_BASE")
    set_config("UCPBRIDGE_STRIPE_API_BASE", f"http://127.0.0.1:{PORT}")
    try:
        yield demarrer_faux_stripe
    finally:
        set_config("UCPBRIDGE_STRIPE_API_BASE", ancien)
        docker_sh("[ -f /tmp/faux-stripe.pid ] && kill $(cat /tmp/faux-stripe.pid) 2>/dev/null; rm -f /tmp/faux-stripe.pid")


def instrument_gpay(boutique):
    return {"id": "instr_gpay", "handler_id": boutique.handler_id("com.google.pay"), "type": "card", "selected": True,
            "credential": {"type": "PAYMENT_GATEWAY", "token": jeton_gpay()}}


def payload(sid):
    return json.loads(sql_val(f"SELECT payload FROM ps_ucpbridge_session WHERE id_session='{sid}'"))


def journal(sid):
    return [tuple(l.split("\t")) for l in sql(f"SELECT operation, status FROM ps_ucpbridge_payment WHERE session_id='{sid}' "
                                               "ORDER BY id_payment").splitlines()]


def verifier_debit_incertain(boutique, sid, cart, reponse):
    """Réponse de finalisation puis rapprochement d'un débit au résultat inconnu."""
    assert reponse.code == 200, reponse.texte[:500]
    assert reponse.doc["status"] == "complete_in_progress", reponse.texte[:500]
    assert "payment_verification" in {m["code"] for m in reponse.doc.get("messages", [])}
    assert "payment_failed" not in {m["code"] for m in reponse.doc.get("messages", [])}
    assert statut(sid) == "complete_in_progress"
    assert payload(sid).get("charge_started_at"), payload(sid)
    assert not commandes(cart)
    # Un nouvel essai, même avec un autre moyen de paiement, ne débite pas une seconde fois.
    autre = complete(boutique, sid)
    assert autre.doc["status"] == "complete_in_progress"
    assert not [j for j in journal(sid) if j[1] == "succeeded"], journal(sid)
    # Le rapprochement classe la session en débit incertain, sans décision automatique.
    vieillir(sid)
    rc, out = rapprocher(10)
    assert rc == 0, out
    assert re.search(rf"^{sid} : .* \[charge_unknown\]$", out, re.M), out
    assert statut(sid) == "failed"
    g = boutique.get(f"/checkout-sessions/{sid}").doc
    assert g["status"] == "canceled" and "payment_verification" in {m["code"] for m in g["messages"]}


@pytest.mark.parametrize("mode", ["500", "502", "coupure"])
def test_debit_au_resultat_inconnu_reste_engage(boutique, faux_stripe, mode):
    faux_stripe(mode)
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    r = complete(boutique, sid, instrument_gpay(boutique))
    assert ("charge", "unknown") in journal(sid), journal(sid)
    verifier_debit_incertain(boutique, sid, cart, r)


def test_passerelle_injoignable_avant_envoi_reste_un_refus(boutique, faux_stripe):
    # Aucun serveur sur ce port : connexion refusée, la requête n'est jamais partie, aucun débit possible.
    set_config("UCPBRIDGE_STRIPE_API_BASE", f"http://127.0.0.1:{PORT - 1}")
    s = boutique.session_prete()
    sid = s["id"]
    r = complete(boutique, sid, instrument_gpay(boutique))
    assert r.code == 200 and r.doc["status"] == "ready_for_complete", r.texte[:500]
    assert "payment_failed" in {m["code"] for m in r.doc["messages"]}
    assert "charge_started_at" not in payload(sid)
    assert journal(sid) == [("charge", "failed")]


# Adaptateur dont charge() lève une exception (module de paiement tiers défaillant) : résultat inconnu lui aussi.
HARNAIS_EXCEPTION = r"""
$m = Module::getInstanceByName('ucpbridge');
class AdaptateurCasse extends UcpBridgeMockProvider {
    public function charge(array $credential, $amount, $currency, array $context) {
        throw new Exception('délai dépassé dans l\'adaptateur (simulé)');
    }
}
UcpBridgePaymentRegistry::get()->register(new AdaptateurCasse());
$ctx = Context::getContext();
$ctx->link = new Link(); $ctx->shop = new Shop(1); $ctx->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT')); $ctx->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
$svc = new UcpBridgeCart($ctx, $m);
$svc->setCaller('platform');
$s = $svc->load(SID, 'checkout');
try {
    list($code, $doc) = $svc->completeCheckout($s, ['payment' => ['instruments' => [INSTR]]]);
    echo "\nRESULT " . json_encode(['code' => $code, 'status' => $doc['status'] ?? null,
        'messages' => array_column($doc['messages'] ?? [], 'code')]);
} catch (Throwable $e) {
    echo "\nEXCEPTION " . $e->getMessage();
}
"""


def test_exception_de_l_adaptateur_pendant_le_debit_reste_engagee(boutique):
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    code = (HARNAIS_EXCEPTION.replace("SID", json.dumps(sid))
            .replace("INSTR", "json_decode(" + json.dumps(json.dumps(boutique.instrument_mock())) + ", true)"))
    out, _ = php_noyau(code)
    assert "RESULT" in out, out[-800:]
    res = json.loads(out.split("RESULT ", 1)[1].splitlines()[0])
    assert res["code"] == 200 and res["status"] == "complete_in_progress", res
    assert "payment_verification" in res["messages"], res
    assert statut(sid) == "complete_in_progress"
    assert payload(sid).get("charge_started_at")
    assert not commandes(cart)
    vieillir(sid)
    rc, out = rapprocher(10)
    assert re.search(rf"^{sid} : .* \[charge_unknown\]$", out, re.M), out
