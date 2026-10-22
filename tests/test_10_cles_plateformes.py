"""Issue #5 : une clé API par plateforme en mode invité, chaque plateforme cloisonnée (paniers, sessions, idempotence).

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import json
import re
import uuid

import pytest

from conftest import BUYER, M_BLANC, appel, cle_api, config, php, set_config


def ajouter(plateforme, nom):
    php(f"Module::getInstanceByName('ucpbridge'); UcpBridgeApiKeys::remove({json.dumps(plateforme)});")
    out = php(f"Module::getInstanceByName('ucpbridge'); echo UcpBridgeApiKeys::add({json.dumps(plateforme)}, {json.dumps(nom)});")
    cle = out.splitlines()[-1] if out else ""
    assert cle.startswith("ucpk_"), out
    return cle


def supprimer(plateforme):
    php(f"Module::getInstanceByName('ucpbridge'); UcpBridgeApiKeys::remove({json.dumps(plateforme)});")


@pytest.fixture
def deux_plateformes():
    a, b = ajouter("plateforme-a", "Plateforme A"), ajouter("plateforme-b", "Plateforme B")
    try:
        yield a, b
    finally:
        supprimer("plateforme-a")
        supprimer("plateforme-b")


def test_deux_plateformes_cloisonnees(boutique, deux_plateformes):
    a, b = deux_plateformes
    corps = {"line_items": [{"item": M_BLANC, "quantity": 1}]}
    cle = str(uuid.uuid4())
    pa = boutique.post("/carts", corps, a, idem=cle)
    assert pa.code == 201, pa.texte[:300]
    # Même Idempotency-Key, même requête, autre plateforme : nouvelle exécution, jamais la réponse de A.
    pb = boutique.post("/carts", corps, b, idem=cle)
    assert pb.code == 201 and pb.entetes.get("idempotent-replayed") != "true", pb.texte[:300]
    assert pb.doc["id"] != pa.doc["id"]
    assert boutique.get(f"/carts/{pa.doc['id']}", jeton=b).code == 404
    assert boutique.get(f"/carts/{pa.doc['id']}", jeton=a).code == 200
    # Sessions de caisse : ni lecture, ni modification, ni finalisation par une autre plateforme.
    s = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}], "buyer": BUYER}, a)
    assert s.code == 201
    sid = s.doc["id"]
    assert boutique.get(f"/checkout-sessions/{sid}", jeton=b).code == 404
    assert boutique.put(f"/checkout-sessions/{sid}", {"buyer": {"email": "pirate@example.test"}}, b).code == 404
    assert boutique.post(f"/checkout-sessions/{sid}/complete", {"payment": {"instruments": [boutique.instrument_mock()]}}, b).code == 404
    assert boutique.post("/checkout-sessions", {"cart_id": pa.doc["id"]}, b).code == 404
    # La clé de la plateforme par défaut ne voit pas non plus les données de A, et inversement.
    assert boutique.get(f"/checkout-sessions/{sid}").code == 404
    d = boutique.post("/carts", corps)
    assert boutique.get(f"/carts/{d.doc['id']}", jeton=a).code == 404
    assert boutique.get(f"/checkout-sessions/{sid}", jeton=a).doc["buyer"]["email"] == BUYER["email"]
    # MCP : même cloisonnement (même authentification).
    def mcp_get(cle_plateforme):
        return appel("POST", boutique.base + "/ucp/v1/mcp", {"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {
            "name": "get_checkout", "arguments": {"id": sid, "meta": {"ucp-agent": {"profile": "https://agent.contrib.test/.well-known/ucp"}}}}},
            cle_plateforme, auto_idem=False)
    r = mcp_get(b)
    assert "error" in r.doc and "result" not in r.doc, r.texte[:300]
    assert mcp_get(a).doc["result"]["structuredContent"]["id"] == sid
    # Clé supprimée : refusée aussitôt.
    supprimer("plateforme-a")
    assert boutique.get(f"/carts/{pa.doc['id']}", jeton=a).code == 401
    assert boutique.get(f"/carts/{pb.doc['id']}", jeton=b).code == 200


def test_migration_de_la_cle_unique(boutique):
    """Boutique en 0.1.2 : une seule clé (UCPBRIDGE_API_KEY). Elle devient la plateforme par défaut, sans rien casser."""
    avant = config("UCPBRIDGE_API_KEYS")
    s = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}]})
    assert s.code == 201
    try:
        set_config("UCPBRIDGE_API_KEY", boutique.cle)
        set_config("UCPBRIDGE_API_KEYS", "{}")
        r = boutique.get(f"/checkout-sessions/{s.doc['id']}")
        assert r.code == 200, r.texte[:300]  # session créée avant la migration : toujours la sienne
        assert config("UCPBRIDGE_API_KEY") == ""
        assert cle_api() == boutique.cle
    finally:
        php("Configuration::deleteByName('UCPBRIDGE_API_KEY');")
        set_config("UCPBRIDGE_API_KEYS", avant)


def back_office(post=None):
    code = "Context::getContext()->employee = new Employee(1); $m = Module::getInstanceByName('ucpbridge');\n"
    if post is not None:
        code += ("$h = $m->getContent(); preg_match('/name=\"ucp_csrf\"[^>]*value=\"([0-9a-f]+)\"/', $h, $t);\n"
                 f"$_POST = json_decode({json.dumps(json.dumps(post))}, true);\n"
                 "if (($_POST['ucp_csrf'] ?? '') === 'JETON') { $_POST['ucp_csrf'] = $t[1]; }\n")
    return php(code + "echo $m->getContent();")


def test_back_office_ajout_regeneration_suppression(boutique):
    supprimer("bo-test")
    html = back_office()
    assert "Plateformes (clés API" in html and boutique.cle in html
    # Jeton anti-CSRF faux : rien n'est créé.
    html = back_office({"submitUcpbridgeApiKeyAdd": "1", "ucp_csrf": "faux", "api_key_id": "bo-test", "api_key_label": "Test BO"})
    assert "Jeton de sécurité invalide" in html and "bo-test" not in config("UCPBRIDGE_API_KEYS")
    try:
        html = back_office({"submitUcpbridgeApiKeyAdd": "1", "ucp_csrf": "JETON", "api_key_id": "bo-test", "api_key_label": "Test BO"})
        cle = cle_api("bo-test")
        assert cle.startswith("ucpk_") and cle in html
        assert boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 1}]}, cle).code == 201
        # Identifiant invalide ou déjà pris : refusé.
        html = back_office({"submitUcpbridgeApiKeyAdd": "1", "ucp_csrf": "JETON", "api_key_id": "bo-test", "api_key_label": "x"})
        assert "invalide ou déjà utilisé" in html and cle_api("bo-test") == cle
        html = back_office({"submitUcpbridgeApiKeyAdd": "1", "ucp_csrf": "JETON", "api_key_id": "Pas Valide", "api_key_label": "x"})
        assert "invalide ou déjà utilisé" in html
        # Régénération : ancienne clé refusée, nouvelle acceptée.
        back_office({"submitUcpbridgeApiKeyRegen": "1", "ucp_csrf": "JETON", "api_key_id": "bo-test"})
        nouvelle = cle_api("bo-test")
        assert nouvelle != cle and re.fullmatch(r"ucpk_[0-9a-f]{48}", nouvelle)
        assert boutique.get("/carts/inconnu", jeton=cle).code == 401
        assert boutique.get("/carts/inconnu", jeton=nouvelle).code == 404
        # Suppression.
        back_office({"submitUcpbridgeApiKeyDelete": "1", "ucp_csrf": "JETON", "api_key_id": "bo-test"})
        assert cle_api("bo-test") == ""
        assert boutique.get("/carts/inconnu", jeton=nouvelle).code == 401
    finally:
        supprimer("bo-test")
    # La plateforme par défaut n'a pas bougé.
    assert cle_api() == boutique.cle


def test_back_office_identifiant_echappe_dans_la_confirmation(boutique):
    """Relecture M2 : l'identifiant de plateforme reçu en POST est échappé dans le message de confirmation."""
    piege = '<img src=x onerror="alert(1)">'
    html = back_office({"submitUcpbridgeApiKeyDelete": "1", "ucp_csrf": "JETON", "api_key_id": piege})
    assert "alert-success" in html, html[-2000:]
    assert piege not in html and "&lt;img src=x" in html
    assert cle_api() == boutique.cle
