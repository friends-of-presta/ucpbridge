"""Scénarios de la relecture indépendante 0.1.0-test (B1, B2, B3, I2, I4 et mineurs), rejoués tels quels.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import http.cookiejar
import json
import re
import threading
import urllib.parse
import urllib.request
import uuid

import pytest

from conftest import (BASE, BUYER, CLIENT_ID, DEMO_EMAIL, DEMO_MDP, M_BLANC, appel, id_commande, jeton_gpay, php,
                      set_config, sql, sql_val)


def id_cart(sid):
    return int(sql_val(f"SELECT id_cart FROM ps_ucpbridge_session WHERE id_session='{sid}'"))


def commandes_du_panier(cart):
    return int(sql_val(f"SELECT COUNT(*) FROM ps_orders WHERE id_cart={cart}"))


def debits(sid):
    """Débits réussis journalisés pour la session (table de journal des paiements du module)."""
    return int(sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_payment WHERE session_id='{sid}' AND operation='charge' "
                       f"AND status='succeeded'") or 0)


def commande_hors_api(cart):
    """Commande passée sur la boutique pour ce panier (copie d'une commande existante, id_cart remplacé)."""
    cols = sql("SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='prestashop' "
               "AND TABLE_NAME='ps_orders' AND COLUMN_NAME NOT IN ('id_order','id_cart','reference','module')")
    sql(f"SET SESSION sql_mode=''; INSERT INTO ps_orders ({cols}, id_cart, reference, module) SELECT {cols}, {cart}, 'NAVIGATEUR', 'ps_wirepayment' "
        "FROM ps_orders ORDER BY id_order DESC LIMIT 1")


def id_client_demo():
    return int(sql_val(f"SELECT id_customer FROM ps_customer WHERE email='{DEMO_EMAIL}' AND is_guest=0 AND deleted=0 "
                       "ORDER BY id_customer LIMIT 1"))


def jeton_direct(client_id=CLIENT_ID, id_customer=None):
    """Jeton d'accès émis par le serveur d'autorisation du module (sans le parcours navigateur)."""
    id_customer = id_customer or id_client_demo()
    out = php("Module::getInstanceByName('ucpbridge'); "
              f"echo json_encode(UcpBridgeOAuth::issueTokens({json.dumps(client_id)}, {int(id_customer)}, "
              "'dev.ucp.shopping.checkout:manage dev.ucp.shopping.order:read')[1]);")
    return json.loads(out.splitlines()[-1])["access_token"]


def complete(boutique, sid, instrument=None, jeton=None, idem=None):
    return boutique.post(f"/checkout-sessions/{sid}/complete", {"payment": {"instruments": [instrument or boutique.instrument_mock()]}},
                         jeton, idem=idem)


# ------------------------------------ B1 : complete simultanés ------------------------------------

@pytest.mark.parametrize("n", [4, 8])
def test_b1_complete_simultanes_une_seule_commande(boutique, n):
    s = boutique.session_prete()
    cart = id_cart(s["id"])
    reponses = [None] * n
    depart = threading.Barrier(n)

    def payer(i):
        depart.wait()
        reponses[i] = complete(boutique, s["id"])  # clés d'idempotence toutes différentes

    fils = [threading.Thread(target=payer, args=(i,)) for i in range(n)]
    for f in fils:
        f.start()
    for f in fils:
        f.join()
    assert commandes_du_panier(cart) == 1
    assert debits(s["id"]) == 1
    statuts = [r.doc.get("status") for r in reponses]
    assert statuts.count("completed") >= 1
    assert set(statuts) <= {"completed", "complete_in_progress"}, statuts
    commandes = {r.doc["order"]["id"] for r in reponses if r.doc.get("status") == "completed"}
    assert len(commandes) == 1


def test_b1_idempotency_key_obligatoire_en_rest(boutique):
    s = boutique.session_prete()
    for methode, chemin, corps in [("POST", f"/checkout-sessions/{s['id']}/complete", {"payment": {"instruments": [boutique.instrument_mock()]}}),
                                   ("POST", "/carts", {"line_items": [{"item": M_BLANC, "quantity": 1}]}),
                                   ("PUT", f"/checkout-sessions/{s['id']}", {"buyer": BUYER}),
                                   ("POST", f"/checkout-sessions/{s['id']}/cancel", {})]:
        r = appel(methode, boutique.api + chemin, corps, boutique.cle, auto_idem=False)
        assert r.code == 400 and r.doc["code"] == "idempotency_key_required", (chemin, r.texte[:300])
    # Lecture et recherche : pas de clé exigée.
    assert appel("POST", boutique.api + "/catalog/search", {"query": "colibri"}, boutique.cle, auto_idem=False).code == 200
    assert commandes_du_panier(id_cart(s["id"])) == 0


# ------------------------------------ B2 : débit sans commande ------------------------------------

def ouvrir_lien(url):
    """Navigateur sans compte : suit le continue_url avec ses cookies."""
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    with op.open(url, timeout=60) as r:
        return r.status, r.read().decode(errors="replace")


def test_b2_continue_url_ouvert_puis_complete_aucun_debit(boutique):
    s = boutique.session_prete()
    cart = id_cart(s["id"])
    ouvrir_lien(s["continue_url"])
    r = complete(boutique, s["id"])
    assert r.code == 200, r.texte[:500]
    assert r.doc["status"] == "canceled"
    assert any(m["code"] == "checkout_handed_off" for m in r.doc["messages"])
    assert "continue_url" not in r.doc
    assert commandes_du_panier(cart) == 0
    assert debits(s["id"]) == 0
    # La session est sortie de l'API : plus aucune modification possible.
    u = boutique.put(f"/checkout-sessions/{s['id']}", {"buyer": {"email": "autre@contrib.test"}})
    assert u.doc["status"] == "canceled"
    assert any(m["code"] == "not_modifiable" for m in u.doc["messages"])


def test_b2_commande_finie_dans_le_navigateur_avant_complete(boutique):
    s = boutique.session_prete()
    cart = id_cart(s["id"])
    # L'acheteur a fini sa commande sur la boutique par un autre moyen (simulé : commande créée sur ce panier).
    commande_hors_api(cart)
    assert commandes_du_panier(cart) == 1
    r = complete(boutique, s["id"])
    assert r.doc["status"] != "completed"
    assert debits(s["id"]) == 0
    assert commandes_du_panier(cart) == 1


def test_b2_echec_de_commande_apres_debit_rembourse(boutique):
    s = boutique.session_prete()
    cart = id_cart(s["id"])
    instr = {"id": "instr_gpay", "handler_id": boutique.handler_id("com.google.pay"), "type": "card", "selected": True,
             "credential": {"type": "PAYMENT_GATEWAY", "token": jeton_gpay()}}
    ancien = sql_val("SELECT value FROM ps_configuration WHERE name='PS_OS_PAYMENT'")
    set_config("PS_OS_PAYMENT", 999999)  # état de commande introuvable : validateOrder échoue après le débit
    cle = str(uuid.uuid4())
    try:
        r = complete(boutique, s["id"], instr, idem=cle)
    finally:
        set_config("PS_OS_PAYMENT", ancien)
    assert r.code == 200, r.texte[:500]
    assert r.doc["status"] == "canceled"
    codes = {m["code"] for m in r.doc["messages"]}
    assert "order_failed" in codes and "payment_refunded" in codes
    assert commandes_du_panier(cart) == 0
    journal = sql(f"SELECT operation, status, transaction_id FROM ps_ucpbridge_payment WHERE session_id='{s['id']}' ORDER BY id_payment")
    lignes = [l.split("\t") for l in journal.splitlines()]
    assert [(l[0], l[1]) for l in lignes] == [("charge", "succeeded"), ("refund", "succeeded")], journal
    assert lignes[0][2].startswith("ch_") and lignes[1][2].startswith("re_")
    # Rejeu avec la même clé : même réponse (clé conservée) ; nouvelle clé : état terminal, aucun nouveau débit.
    b = complete(boutique, s["id"], instr, idem=cle)
    assert b.entetes.get("idempotent-replayed") == "true" and b.doc["status"] == "canceled"
    c = complete(boutique, s["id"], instr)
    assert c.doc["status"] == "canceled"
    assert debits(s["id"]) == 1
    assert "ucpbridge" in sql_val("SELECT message FROM ps_log WHERE message LIKE 'ucpbridge incident%' ORDER BY id_log DESC LIMIT 1")


# ------------------------------------ B3 : cloisonnement ------------------------------------

def test_b3a_enumeration_des_commandes_par_la_cle_api(boutique):
    fin = complete(boutique, boutique.session_prete()["id"]).doc
    assert fin["status"] == "completed"
    oid = id_commande(fin["order"]["id"])
    assert not re.fullmatch(r"\d+", fin["order"]["id"])  # identifiant opaque, pas l'id_order
    # Énumération par identifiant séquentiel : rien n'est lisible.
    for n in range(max(1, oid - 15), oid + 1):
        r = boutique.get(f"/orders/{n}")
        assert r.code == 404, (n, r.texte[:200])
    assert boutique.get(f"/orders/{fin['order']['id']}").code == 200  # propriétaire : lisible


def test_b3b_jeton_oauth_ne_lit_pas_la_commande_d_un_invite(boutique):
    fin = complete(boutique, boutique.session_prete()["id"]).doc
    ref = fin["order"]["id"]
    acces = jeton_direct()
    r = boutique.get(f"/orders/{ref}", jeton=acces)
    assert r.code == 404, r.texte[:300]
    assert "fulfillment" not in (r.doc or {})


def test_b3c_jeton_oauth_ne_modifie_pas_la_session_d_autrui(boutique):
    s = boutique.session_prete()
    acces = jeton_direct()
    assert boutique.get(f"/checkout-sessions/{s['id']}", jeton=acces).code == 404
    r = boutique.put(f"/checkout-sessions/{s['id']}", {"buyer": {"email": "pirate@example.test"}}, acces)
    assert r.code == 404
    assert boutique.get(f"/checkout-sessions/{s['id']}").doc["buyer"]["email"] == BUYER["email"]
    p = boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 1}]}).doc
    assert boutique.get(f"/carts/{p['id']}", jeton=acces).code == 404
    assert boutique.post("/checkout-sessions", {"cart_id": p["id"]}, acces).code == 404


def test_b3_deux_clients_oauth_du_meme_acheteur_cloisonnes(boutique):
    php("Module::getInstanceByName('ucpbridge'); UcpBridgeOAuth::registerClient('autre-agent-test', 'Autre agent', ['http://localhost:8766/cb']);")
    a = jeton_direct(CLIENT_ID)
    b = jeton_direct("autre-agent-test")
    s = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}]}, a)
    assert s.code == 201
    assert boutique.get(f"/checkout-sessions/{s.doc['id']}", jeton=a).code == 200
    assert boutique.get(f"/checkout-sessions/{s.doc['id']}", jeton=b).code == 404
    # Et la clé plateforme ne voit pas la session du client OAuth.
    assert boutique.get(f"/checkout-sessions/{s.doc['id']}").code == 404


# ------------------------------------ I4 : idempotence ------------------------------------

def test_i4_refus_de_scope_ne_bloque_pas_la_cle(boutique):
    acces = php("Module::getInstanceByName('ucpbridge'); "
                f"echo UcpBridgeOAuth::issueTokens({json.dumps(CLIENT_ID)}, {id_client_demo()}, 'dev.ucp.shopping.order:read')[1]['access_token'];")
    acces = acces.splitlines()[-1]
    cle = str(uuid.uuid4())
    corps = {"line_items": [{"item": M_BLANC, "quantity": 1}]}
    r = boutique.post("/checkout-sessions", corps, acces, idem=cle)
    assert r.code == 403 and r.doc["messages"][0]["code"] == "insufficient_scope"
    r = boutique.post("/checkout-sessions", corps, acces, idem=cle)
    assert r.code == 403  # pas de 409 idempotency_in_progress


# ------------------------------------ I2 : révocation par l'acheteur ------------------------------------

def test_i2_mes_acces_agents_revocation_par_le_client(boutique):
    from playwright.sync_api import sync_playwright
    acces = jeton_direct()
    assert boutique.get("/orders/ord_000000000000000000000000", jeton=acces).code == 404  # jeton valide
    page_acces = f"{BASE}/index.php?fc=module&module=ucpbridge&controller=access"
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(locale="fr-FR")
        page.goto(f"{BASE}/index.php?controller=authentication&back=" + urllib.parse.quote(page_acces))
        page.fill("#login-form input[name=email]", DEMO_EMAIL)
        page.fill("#login-form input[name=password]", DEMO_MDP)
        page.click("#login-form button[type=submit]")
        page.wait_for_load_state("networkidle")
        page.goto(f"{BASE}/index.php?controller=my-account")
        assert page.locator("#ucpbridge-access-link").count() >= 1  # le thème peut afficher le lien deux fois
        page.goto(page_acces)
        assert page.get_attribute("main", "data-ucp-page") == "agent-access" and CLIENT_ID in page.inner_text("body")
        page.click(f"form[data-client='{CLIENT_ID}'] button[type=submit]")
        page.wait_for_load_state("networkidle")
        assert page.locator("p.ok").count() == 1 and page.locator(f"form[data-client='{CLIENT_ID}']").count() == 0
        nav.close()
    assert boutique.get("/orders/ord_000000000000000000000000", jeton=acces).code == 401


def test_i2_liste_et_revocation_cote_marchand(boutique):
    acces = jeton_direct()
    html = php("Context::getContext()->employee = new Employee(1); echo Module::getInstanceByName('ucpbridge')->getContent();")
    assert 'id="ucpbridge-grants"' in html and CLIENT_ID in html
    jeton_bo = re.search(r'name="ucp_csrf"[^>]*value="([0-9a-f]+)"', html).group(1)
    php("Context::getContext()->employee = new Employee(1); "
        f"$_POST = ['submitUcpbridgeRevoke' => '1', 'ucp_csrf' => '{jeton_bo}', 'revoke_client' => {json.dumps(CLIENT_ID)}, "
        f"'revoke_customer' => '{id_client_demo()}']; echo Module::getInstanceByName('ucpbridge')->getContent();")
    assert boutique.get("/orders/ord_000000000000000000000000", jeton=acces).code == 401


# ------------------------------------ Mineurs ------------------------------------

def test_m6_origin_etranger_refuse_en_mcp(boutique):
    r = appel("POST", f"{BASE}/ucp/v1/mcp", {"jsonrpc": "2.0", "id": 1, "method": "ping"}, boutique.cle,
              entetes={"Origin": "https://malveillant.example"}, auto_idem=False)
    assert r.code == 403
    r = appel("POST", f"{BASE}/ucp/v1/mcp", {"jsonrpc": "2.0", "id": 1, "method": "ping"}, boutique.cle,
              entetes={"Origin": BASE}, auto_idem=False)
    assert r.code == 200


def test_m7_get_product_annonce_une_capacite_du_profil(boutique):
    r = boutique.post("/catalog/product", {"id": "demo_1"})
    for cap in r.doc["ucp"]["capabilities"]:
        assert cap in boutique.profil["capabilities"], cap


def test_m4_cle_stripe_non_reaffichee_en_back_office(boutique):
    html = php("Context::getContext()->employee = new Employee(1); echo Module::getInstanceByName('ucpbridge')->getContent();")
    assert "sk_test_contrib" not in html
