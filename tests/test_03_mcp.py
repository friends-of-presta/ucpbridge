"""Fonctionnalité 2 : transport MCP (JSON-RPC 2.0 sur HTTP streamable) du service dev.ucp.shopping.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import json
import os
import re
import shutil
import subprocess
import sys
import uuid

import pytest

from conftest import BANC, BASE, BUYER, DEST_FR, M_BLANC, appel, id_commande, sql_val

sys.path.insert(0, BANC)
from mcp_client import ClientMcp, McpErreur, scenario, variante  # noqa: E402

OUTILS = ["search_catalog", "get_product", "create_cart", "get_cart", "update_cart", "cancel_cart", "create_checkout",
          "get_checkout", "update_checkout", "complete_checkout", "cancel_checkout", "get_order"]


@pytest.fixture
def mcp(boutique):
    url = next(s["endpoint"] for s in boutique.profil["services"]["dev.ucp.shopping"] if s["transport"] == "mcp")
    c = ClientMcp(url, boutique.cle)
    c.initialiser()
    return c


def test_service_mcp_declare_dans_le_profil(boutique):
    services = {s["transport"]: s for s in boutique.profil["services"]["dev.ucp.shopping"]}
    assert set(services) == {"rest", "mcp"}
    m = services["mcp"]
    assert m["endpoint"] == f"{BASE}/ucp/v1/mcp"
    assert m["schema"] == "https://ucp.dev/2026-04-08/services/shopping/mcp.openrpc.json"
    assert m["version"] == "2026-04-08"


def test_mcp_exige_l_authentification(boutique):
    c = ClientMcp(f"{BASE}/ucp/v1/mcp")
    with pytest.raises(McpErreur) as e:
        c.initialiser()
    assert e.value.http == 401


def test_mcp_get_refuse(boutique):
    r = appel("GET", f"{BASE}/ucp/v1/mcp", jeton=boutique.cle)
    assert r.code == 405


def test_initialize_et_tools_list(mcp):
    noms = [t["name"] for t in mcp.outils()]
    assert noms == OUTILS


def test_scenario_tshirt_colibri_taille_m(mcp):
    from playwright.sync_api import sync_playwright
    lignes = []
    panier = scenario(mcp, lignes.append)
    assert panier["line_items"][0]["item"]["sku"] == "demo_1-M-BLANC"
    assert [l["type"] for l in panier["links"]][0] == "terms_of_service"
    with open(os.path.join(BANC, "mcp-client-sortie.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(lignes) + "\n")
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(locale="fr-FR", viewport={"width": 1280, "height": 900})
        page.goto(panier["continue_url"], wait_until="networkidle")
        texte = page.inner_text("body")
        assert "colibri" in texte.lower() and re.search(r"Taille\s*:\s*M\b", texte), texte[:1500]
        page.screenshot(path=os.path.join(BANC, "capture-mcp-continue-url.png"))
        nav.close()


def test_meme_logique_que_le_rest(mcp, boutique):
    corps = {"line_items": [{"item": M_BLANC, "quantity": 2}], "discounts": {"codes": ["BIENVENUE10"]}}
    rest = boutique.post("/carts", corps).doc
    via_mcp = mcp.appeler("create_cart", {"cart": corps})
    assert set(rest) == set(via_mcp)
    assert rest["totals"] == via_mcp["totals"] and rest["discounts"] == via_mcp["discounts"]
    assert mcp.appeler("get_cart", {"id": via_mcp["id"]})["id"] == via_mcp["id"]


def test_variant_required_resultat_metier(mcp):
    p = mcp.appeler("search_catalog", {"catalog": {"query": "colibri"}})["products"][0]
    r = mcp.appeler("create_cart", {"cart": {"line_items": [{"item": {"id": p["id"]}, "quantity": 1}]}})
    assert r["ucp"]["status"] == "error" and r["messages"][0]["code"] == "variant_required"


def test_caisse_complete_en_mcp(mcp, boutique):
    s = mcp.appeler("create_checkout", {"checkout": {"line_items": [{"item": M_BLANC, "quantity": 1}], "buyer": BUYER}})
    s = mcp.appeler("update_checkout", {"id": s["id"], "checkout": {"fulfillment": {"methods": [
        {"type": "shipping", "destinations": [DEST_FR], "selected_destination_id": "dest_1"}]}}})
    m = s["fulfillment"]["methods"][0]
    m["groups"][0]["selected_option_id"] = m["groups"][0]["options"][0]["id"]
    s = mcp.appeler("update_checkout", {"id": s["id"], "checkout": {"fulfillment": {"methods": [m]}}})
    assert s["status"] == "ready_for_complete"
    assert any(x.get("presentation") == "disclosure" for x in s["messages"])
    with pytest.raises(McpErreur) as e:
        mcp.appeler("complete_checkout", {"id": s["id"], "checkout": {"payment": {"instruments": [boutique.instrument_mock()]}}}, idem="")
    assert e.value.erreur["code"] == -32602  # idempotency-key obligatoire
    cle = str(uuid.uuid4())
    fin = mcp.appeler("complete_checkout", {"id": s["id"], "checkout": {"payment": {"instruments": [boutique.instrument_mock()]}}}, idem=cle)
    assert fin["status"] == "completed"
    rejeu = mcp.appeler("complete_checkout", {"id": s["id"], "checkout": {"payment": {"instruments": [boutique.instrument_mock()]}}}, idem=cle)
    assert rejeu["order"]["id"] == fin["order"]["id"]
    o = mcp.appeler("get_order", {"id": fin["order"]["id"]})
    assert o["id"] == fin["order"]["id"]
    agent = sql_val(f"SELECT agent FROM ps_ucpbridge_proof WHERE id_order={id_commande(fin['order']['id'])}")
    assert agent == 'profile="https://agent.contrib.test/.well-known/ucp"'


def test_conflit_d_idempotence_en_erreur_protocole(mcp):
    cle = str(uuid.uuid4())
    mcp.appeler("create_cart", {"cart": {"line_items": [{"item": M_BLANC, "quantity": 1}]}}, idem=cle)
    with pytest.raises(McpErreur) as e:
        mcp.appeler("create_cart", {"cart": {"line_items": [{"item": M_BLANC, "quantity": 3}]}}, idem=cle)
    assert e.value.http == 409 and e.value.erreur["code"] == -32000


INSPECTEUR = "@modelcontextprotocol/inspector@2.9.0"


@pytest.mark.skipif(not shutil.which("npx"), reason="npx absent")
def test_inspecteur_officiel_mcp(boutique):
    """Inspecteur officiel MCP en mode CLI : tools/list puis « t-shirt colibri taille M » jusqu'au continue_url."""
    base = ["npx", "-y", INSPECTEUR, "--cli", f"{BASE}/ucp/v1/mcp", "--transport", "http",
            "--header", f"Authorization: Bearer {boutique.cle}"]
    meta = "meta=" + json.dumps({"ucp-agent": {"profile": "https://agent.contrib.test/.well-known/ucp"}})
    journal = []

    def inspecteur(*args, affiche):
        r = subprocess.run(base + list(args), capture_output=True, text=True, timeout=300)
        assert r.returncode == 0, r.stderr[-2000:]
        journal.append("$ npx " + INSPECTEUR + f" --cli {BASE}/ucp/v1/mcp --transport http "
                       "--header 'Authorization: Bearer <clé API>' " + " ".join(a if " " not in a else f"'{a}'" for a in args))
        doc = json.loads(r.stdout)
        journal.append(json.dumps(affiche(doc), ensure_ascii=False, indent=1) + "\n")
        return doc

    liste = inspecteur("--method", "tools/list", affiche=lambda d: [t["name"] for t in d["tools"]])
    assert [t["name"] for t in liste["tools"]] == OUTILS
    res = inspecteur("--method", "tools/call", "--tool-name", "search_catalog", "--tool-arg", meta,
                     "--tool-arg", 'catalog={"query": "t-shirt colibri"}',
                     affiche=lambda d: {"products[0]": {k: d["structuredContent"]["products"][0][k] for k in ("id", "title")}})
    p = res["structuredContent"]["products"][0]
    det = inspecteur("--method", "tools/call", "--tool-name", "get_product", "--tool-arg", meta,
                     "--tool-arg", "catalog=" + json.dumps({"id": p["id"]}),
                     affiche=lambda d: {"variants": [f'{v["id"]} {v["sku"]} {v["title"]}' for v in d["structuredContent"]["product"]["variants"]]})
    v = variante(det["structuredContent"]["product"], Taille="M", Couleur="Blanc")
    meta_idem = "meta=" + json.dumps({"ucp-agent": {"profile": "https://agent.contrib.test/.well-known/ucp"},
                                      "idempotency-key": str(uuid.uuid4())})
    panier = inspecteur("--method", "tools/call", "--tool-name", "create_cart", "--tool-arg", meta_idem,
                        "--tool-arg", "cart=" + json.dumps({"line_items": [{"item": {"id": p["id"], "variant_id": v["id"]}, "quantity": 1}]}),
                        affiche=lambda d: {k: d["structuredContent"][k] for k in ("id", "continue_url")}
                        | {"line_items[0]": d["structuredContent"]["line_items"][0]["item"]["title"]})
    assert panier["structuredContent"]["line_items"][0]["item"]["sku"] == "demo_1-M-BLANC"
    assert "controller=handoff" in panier["structuredContent"]["continue_url"] or "/handoff" in panier["structuredContent"]["continue_url"]
    with open(os.path.join(BANC, "mcp-inspector-sortie.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(journal))
