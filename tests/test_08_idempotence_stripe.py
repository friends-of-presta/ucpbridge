"""Issue #3 : clé d'idempotence Stripe et nouveaux essais après un refus.

stripe-mock ne mémorise pas les clés d'idempotence. Ce test lance donc un petit faux Stripe (serveur HTTP du test,
joint par la boutique à l'adresse host.docker.internal) qui applique la règle de Stripe : même Idempotency-Key et
mêmes paramètres = réponse précédente rejouée (refus compris) ; même clé et paramètres différents = 400
idempotency_error. Il refuse la première demande de débit (carte refusée), puis transmet les suivantes au
stripe-mock du banc (port 12111), qui renvoie un objet charge complet.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import http.server
import json
import os
import threading
import urllib.error
import urllib.request

import pytest

from conftest import M_BLANC, config, jeton_gpay, set_config, sql
from test_04_relecture import complete, id_cart
from test_05_relecture2 import commandes

PORT = 12197
STRIPE_MOCK = os.environ.get("STRIPE_MOCK", "http://localhost:12111")
# Adresse d'écoute du faux Stripe : la boucle locale suffit avec Docker Desktop ; sous Linux (intégration continue),
# la boutique le joint par la passerelle docker (host-gateway), il faut donc écouter sur 0.0.0.0.
HOTE = os.environ.get("FAUX_STRIPE_HOTE", "127.0.0.1")


class FauxStripe(http.server.BaseHTTPRequestHandler):
    refus = 1
    cles = {}

    def do_POST(self):
        cle = self.headers.get("Idempotency-Key", "")
        params = self.rfile.read(int(self.headers.get("Content-Length") or 0)).decode()
        prec = FauxStripe.cles.get(cle)
        if prec and prec["params"] != params:
            code, corps = 400, {"error": {"type": "idempotency_error", "message": "Keys for idempotent requests can only be "
                                          "used with the same parameters they were first used with."}}
        elif prec:
            code, corps = prec["code"], prec["corps"]
        else:
            if FauxStripe.refus > 0:
                FauxStripe.refus -= 1
                code, corps = 402, {"error": {"type": "card_error", "code": "card_declined", "decline_code": "insufficient_funds",
                                              "message": "Your card has insufficient funds."}}
            else:
                code, corps = FauxStripe.debit_stripe_mock(params, self.headers.get("Authorization", ""))
            FauxStripe.cles[cle] = {"params": params, "code": code, "corps": corps}
        texte = json.dumps(corps).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(texte)))
        self.end_headers()
        self.wfile.write(texte)

    @staticmethod
    def debit_stripe_mock(params, auth):
        req = urllib.request.Request(STRIPE_MOCK + "/v1/charges", data=params.encode(), method="POST",
                                     headers={"Authorization": auth, "Content-Type": "application/x-www-form-urlencoded"})
        try:
            with urllib.request.urlopen(req, timeout=10) as r:
                return r.status, json.loads(r.read())
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read())

    def log_message(self, *a):
        pass


@pytest.fixture
def faux_stripe():
    FauxStripe.refus, FauxStripe.cles = 1, {}
    serveur = http.server.ThreadingHTTPServer((HOTE, PORT), FauxStripe)
    threading.Thread(target=serveur.serve_forever, daemon=True).start()
    ancien = config("UCPBRIDGE_STRIPE_API_BASE")
    set_config("UCPBRIDGE_STRIPE_API_BASE", f"http://host.docker.internal:{PORT}")
    try:
        yield FauxStripe
    finally:
        set_config("UCPBRIDGE_STRIPE_API_BASE", ancien)
        serveur.shutdown()
        serveur.server_close()


def instrument_gpay(boutique):
    return {"id": "instr_gpay", "handler_id": boutique.handler_id("com.google.pay"), "type": "card", "selected": True,
            "credential": {"type": "PAYMENT_GATEWAY", "token": jeton_gpay()}}


def debits(sid):
    return sql(f"SELECT status FROM ps_ucpbridge_payment WHERE session_id='{sid}' AND operation='charge' ORDER BY id_payment").splitlines()


def test_refus_puis_nouvel_essai_meme_carte(boutique, faux_stripe):
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    carte = instrument_gpay(boutique)  # le même jeton, à l'octet près, pour chaque essai
    r = complete(boutique, sid, carte)
    assert r.doc["status"] == "ready_for_complete", r.texte[:500]
    assert "insufficient funds" in next(m["content"] for m in r.doc["messages"] if m["code"] == "payment_failed")
    # Compte réapprovisionné : le même moyen de paiement doit être débité, pas recevoir le refus mis en cache.
    r = complete(boutique, sid, carte)
    assert r.doc["status"] == "completed", r.texte[:500]
    assert debits(sid) == ["failed", "succeeded"]
    assert len(commandes(cart)) == 1


def test_panier_modifie_apres_refus_puis_meme_carte(boutique, faux_stripe):
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    carte = instrument_gpay(boutique)  # le même jeton, à l'octet près, pour chaque essai
    r = complete(boutique, sid, carte)
    assert r.doc["status"] == "ready_for_complete", r.texte[:500]
    # L'acheteur ajoute un article : nouveau montant, même carte.
    m = r.doc["fulfillment"]["methods"][0]
    u = boutique.put(f"/checkout-sessions/{sid}", {"line_items": [{"item": M_BLANC, "quantity": 2}], "fulfillment": {"methods": [m]}})
    assert u.code == 200 and u.doc["status"] == "ready_for_complete", u.texte[:500]
    r = complete(boutique, sid, carte)
    assert r.doc["status"] == "completed", r.texte[:500]
    montant = sql(f"SELECT amount FROM ps_ucpbridge_payment WHERE session_id='{sid}' AND status='succeeded'")
    assert int(montant) == sum(t["amount"] for t in u.doc["totals"] if t["type"] == "total")
    assert len(commandes(cart)) == 1
