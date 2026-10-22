"""Fonctionnalité 1 : CGV, rétractation, RGPD (links, disclosure, relecture des CGV, journal de preuve,
buyer_consent, e-mail de confirmation et rétractation en ligne).

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import json
import os
import re
import urllib.parse
import uuid

import pytest

from conftest import (BANC, BASE, BUYER, DEST_FR, MAILPIT, M_BLANC, admin_dir, admin_login, appel, id_commande, attendre_mails, config, mail, php,
                      set_config, sql, sql_val, total)

TYPES = ["terms_of_service", "privacy_policy", "refund_policy", "shipping_policy"]
DEST_CH = {"id": "dest_ch", "first_name": "Agent", "last_name": "Contrib", "street_address": "1 rue du Rhône",
           "address_locality": "Genève", "postal_code": "1204", "address_country": "CH"}


def acheteur():
    return {"email": f"acheteur-{uuid.uuid4().hex[:10]}@contrib.test", "first_name": "Camille", "last_name": "Martin"}


def disclosures(doc):
    return [m for m in doc.get("messages", []) if m.get("presentation") == "disclosure"]


# ------------------------------------ links ------------------------------------

def test_links_dans_le_panier_et_la_caisse(boutique):
    p = boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 1}]}).doc
    s = boutique.session_prete()
    for doc in (p, s):
        assert [l["type"] for l in doc["links"]] == TYPES
        tos = doc["links"][0]
        assert tos["title"] == "Conditions d'utilisation"
        assert "3-conditions-utilisation" in tos["url"] or "id_cms=3" in tos["url"]
        assert doc["links"][1]["title"] == "Mentions légales"
        assert doc["links"][3]["title"] == "Livraison"


def test_links_configurables(boutique):
    set_config("UCPBRIDGE_CMS_TERMS", 4)
    try:
        s = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}]}).doc
        assert s["links"][0]["title"] == "A propos"
    finally:
        set_config("UCPBRIDGE_CMS_TERMS", 3)


def test_configuration_back_office_avec_jeton_csrf(boutique):
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(locale="fr-FR", viewport={"width": 1280, "height": 1000})
        admin_login(page)
        page.goto(f"{BASE}/{admin_dir()}/index.php?controller=AdminModules&configure=ucpbridge")
        if "compromised" in page.url:  # lien sans jeton d'administration : PrestaShop demande confirmation
            page.click("text=Oui, je comprends les risques")
        page.wait_for_selector("select[name=UCPBRIDGE_CMS_SHIPPING]")
        page.select_option("select[name=UCPBRIDGE_CMS_SHIPPING]", "5")
        page.click("button[name=submitUcpbridge]")
        page.wait_for_load_state("networkidle")
        assert config("UCPBRIDGE_CMS_SHIPPING") == "5"
        page.screenshot(path=os.path.join(BANC, "capture-config-module.png"), full_page=True)
        # Même formulaire sans jeton CSRF du module : refusé, configuration inchangée.
        page.evaluate("document.querySelector('input[name=ucp_csrf]').value = 'faux'")
        page.select_option("select[name=UCPBRIDGE_CMS_SHIPPING]", "1")
        page.click("button[name=submitUcpbridge]")
        page.wait_for_load_state("networkidle")
        assert config("UCPBRIDGE_CMS_SHIPPING") == "5"
        nav.close()
    set_config("UCPBRIDGE_CMS_SHIPPING", 1)


# ------------------------------------ disclosure ------------------------------------

def test_mention_retractation_ue(boutique):
    s = boutique.session_prete(dest=DEST_FR)
    d = disclosures(s)
    assert len(d) == 1
    assert d[0]["type"] == "warning" and d[0]["code"] == "withdrawal_right"
    assert d[0]["content"] and d[0]["presentation"] == "disclosure" and d[0]["url"]
    p = boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 1}]}).doc
    assert len(disclosures(p)) == 1  # pays de la boutique (FR) tant qu'aucune adresse n'est connue


def test_pas_de_mention_hors_ue(boutique):
    r = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}], "buyer": BUYER})
    s = boutique.put(f"/checkout-sessions/{r.doc['id']}", {"fulfillment": {"methods": [
        {"type": "shipping", "destinations": [DEST_CH], "selected_destination_id": "dest_ch"}]}}).doc
    assert s["fulfillment"]["methods"][0]["destinations"][0]["address_country"] == "CH", s.get("messages")
    assert disclosures(s) == []


# ------------------------------------ buyer_consent ------------------------------------

def test_capacite_buyer_consent_declaree(boutique):
    c = boutique.profil["capabilities"]["dev.ucp.shopping.buyer_consent"][0]
    assert c["extends"] == "dev.ucp.shopping.checkout"


def commander(boutique, buyer, extra=None):
    s = boutique.session_prete(buyer=buyer, extra=extra)
    r = boutique.payer_mock(s["id"])
    assert r.doc["status"] == "completed", r.texte[:800]
    return s, r.doc


def test_consentement_marketing_explicite(boutique):
    b = acheteur() | {"consent": {"marketing": True, "analytics": False}}
    s, fin = commander(boutique, b)
    assert s["buyer"]["consent"] == {"marketing": True, "analytics": False}
    oid = id_commande(fin["order"]["id"])
    assert sql_val(f"SELECT c.newsletter FROM ps_orders o JOIN ps_customer c USING (id_customer) WHERE o.id_order={oid}") == "1"
    assert sql_val(f"SELECT c.optin FROM ps_orders o JOIN ps_customer c USING (id_customer) WHERE o.id_order={oid}") == "0"
    preuve = json.loads(sql_val(f"SELECT consent FROM ps_ucpbridge_proof WHERE id_order={oid}"))
    assert preuve == {"analytics": False, "preferences": None, "marketing": True, "sale_of_data": None}


def test_absence_de_consentement(boutique):
    s, fin = commander(boutique, acheteur() | {"consent": {"marketing": "true"}})
    oid = id_commande(fin["order"]["id"])
    assert "consent" not in s["buyer"] or s["buyer"]["consent"] == {}
    assert sql_val(f"SELECT CONCAT(c.newsletter, c.optin) FROM ps_orders o JOIN ps_customer c USING (id_customer) WHERE o.id_order={oid}") == "00"
    preuve = json.loads(sql_val(f"SELECT consent FROM ps_ucpbridge_proof WHERE id_order={oid}"))
    assert set(preuve.values()) == {None}


def test_optin_partenaires(boutique):
    s, fin = commander(boutique, acheteur() | {"consent": {"marketing": True, "sale_of_data": True}})
    oid = id_commande(fin["order"]["id"])
    assert sql_val(f"SELECT CONCAT(c.newsletter, c.optin) FROM ps_orders o JOIN ps_customer c USING (id_customer) WHERE o.id_order={oid}") == "11"


# ------------------------------------ journal de preuve ------------------------------------

def test_journal_de_preuve(boutique):
    s, fin = commander(boutique, acheteur())
    oid = id_commande(fin["order"]["id"])
    row = sql(f"SELECT session_id, agent, terms_version, links, disclosures, totals FROM ps_ucpbridge_proof WHERE id_order={oid}")
    sid, agent, version, links, disc, totaux = row.split("\t")
    assert sid == s["id"]
    assert agent.startswith('profile="https://agent.contrib.test/')
    assert re.match(r"^cms3-[0-9a-f]{12}$", version)
    assert [l["type"] for l in json.loads(links)] == TYPES
    assert json.loads(disc)[0]["code"] == "withdrawal_right"
    assert [t["amount"] for t in json.loads(totaux) if t["type"] == "total"][0] == total(s)
    html = php(f"echo Module::getInstanceByName('ucpbridge')->hookDisplayAdminOrderMain(['id_order' => {oid}]);")
    assert 'id="ucpbridge-proof"' in html and version in html and "agent.contrib.test" in html
    assert "<script" not in html


def test_journal_visible_sur_la_fiche_commande(boutique):
    from playwright.sync_api import sync_playwright
    s, fin = commander(boutique, acheteur())
    oid = id_commande(fin["order"]["id"])
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(locale="fr-FR", viewport={"width": 1400, "height": 1000})
        admin_login(page)
        lien = page.locator("a[href*='sell/orders']").first.get_attribute("href")
        jeton = urllib.parse.parse_qs(urllib.parse.urlparse(lien).query)["_token"][0]
        page.goto(f"{BASE}/{lien.split('/index.php')[0].strip('/').split('/')[-1]}/index.php/sell/orders/{oid}/view?_token={jeton}")
        bloc = page.locator("#ucpbridge-proof")
        bloc.wait_for(timeout=15000)
        assert bloc.get_attribute("data-ucp-withdrawal") == "none"
        bloc.scroll_into_view_if_needed()
        bloc.screenshot(path=os.path.join(BANC, "capture-preuve-commande.png"))
        nav.close()


# ------------------------------------ relecture des CGV ------------------------------------

@pytest.fixture
def relecture():
    set_config("UCPBRIDGE_TERMS_REVIEW", 1)
    yield
    set_config("UCPBRIDGE_TERMS_REVIEW", 0)


def test_relecture_desactivee_par_defaut(boutique):
    assert config("UCPBRIDGE_TERMS_REVIEW") == "0"
    assert boutique.session_prete()["status"] == "ready_for_complete"


def test_relecture_des_cgv_chez_le_marchand(boutique, relecture):
    from playwright.sync_api import sync_playwright
    s = boutique.session_prete(buyer=acheteur())
    assert s["status"] == "requires_escalation"
    m = [x for x in s["messages"] if x.get("severity") == "requires_buyer_review"]
    assert m and m[0]["code"] == "terms_review_required"
    url = s["continue_url"]
    assert "controller=terms" in url or "/terms" in url
    refus = boutique.payer_mock(s["id"]).doc
    assert refus["status"] == "requires_escalation" and "order" not in refus

    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(locale="fr-FR", viewport={"width": 1000, "height": 1300})
        r = page.goto(url.replace("sig=", "sig=0"))
        assert r.status == 403 and page.get_attribute("main", "data-ucp-page") == "invalid-link"
        page.goto(url)
        corps = page.inner_text("body")
        assert page.get_attribute("main", "data-ucp-page") == "terms-review"
        assert "colibri" in corps.lower() and "Conditions d'utilisation" in corps  # titre de la page CMS de la boutique
        assert page.locator("input[type=checkbox][name=accept_terms]").count() == 1
        page.click("button[type=submit]")  # sans cocher : refusé
        assert page.get_attribute("main", "data-ucp-page") == "terms-review" and page.locator("p.err").count() == 1
        page.goto(url)
        page.check("input[name=accept_terms]")
        page.screenshot(path=os.path.join(BANC, "capture-acceptation-cgv.png"), full_page=True)
        page.click("button[type=submit]")
        assert page.get_attribute("main", "data-ucp-page") == "terms-accepted"
        nav.close()

    s = boutique.get(f"/checkout-sessions/{s['id']}").doc
    assert s["status"] == "ready_for_complete"
    fin = boutique.payer_mock(s["id"]).doc
    assert fin["status"] == "completed"
    acc = json.loads(sql_val(f"SELECT terms_acceptance FROM ps_ucpbridge_proof WHERE id_order={id_commande(fin['order']['id'])}"))
    assert acc["ip"] and acc["version"].startswith("cms3-") and acc["accepted_at"]


def test_formulaire_cgv_protege_contre_csrf(boutique, relecture):
    s = boutique.session_prete(buyer=acheteur())
    url = s["continue_url"]
    r = appel("POST", url, form={"accept_terms": "1", "csrf": "faux"})
    assert 'data-ucp-page="terms-accepted"' not in r.texte
    assert boutique.get(f"/checkout-sessions/{s['id']}").doc["status"] == "requires_escalation"


# ------------------------------- e-mail de confirmation et rétractation -------------------------------

def test_email_de_confirmation_et_retractation(boutique):
    from playwright.sync_api import sync_playwright
    b = acheteur()
    s, fin = commander(boutique, b)
    oid = id_commande(fin["order"]["id"])
    msgs = attendre_mails(b["email"])
    # E-mail de confirmation : celui qui porte le bloc légal du module (lien de rétractation signé).
    retrait = re.compile(r'href="([^"]*controller=withdraw[^"]*|[^"]*/withdraw\?[^"]*)"')
    conf = [m for m in msgs if retrait.search(mail(m["ID"])["HTML"])]
    assert conf, [m["Subject"] for m in msgs]
    html = mail(conf[0]["ID"])["HTML"]
    assert "Conditions d'utilisation" in html or "Conditions d&#039;utilisation" in html  # titre de la page CMS
    url = retrait.search(html).group(1).replace("&amp;", "&")

    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(locale="fr-FR", viewport={"width": 1200, "height": 1000})
        page.goto(f"{MAILPIT}/view/{conf[0]['ID']}.html")
        page.wait_for_load_state("networkidle")
        page.screenshot(path=os.path.join(BANC, "capture-mail-confirmation.png"), full_page=True)
        bloc = page.locator("table:has(a[href*='withdraw'])").last
        bloc.scroll_into_view_if_needed()
        bloc.screenshot(path=os.path.join(BANC, "capture-mail-bloc-legal.png"))
        r = page.goto(url.replace("sig=", "sig=0"))
        assert r.status == 403 and page.get_attribute("main", "data-ucp-page") == "invalid-link"
        page.goto(url)
        assert page.get_attribute("main", "data-ucp-page") == "withdrawal-form"
        page.screenshot(path=os.path.join(BANC, "capture-retractation.png"), full_page=True)
        page.click("button[type=submit]")
        assert page.get_attribute("main", "data-ucp-page") == "withdrawal-recorded" and b["email"] in page.inner_text("body")
        page.screenshot(path=os.path.join(BANC, "capture-retractation-accusee.png"), full_page=True)
        page.goto(url)  # deuxième visite : déjà enregistrée, pas de doublon
        assert page.get_attribute("main", "data-ucp-page") == "withdrawal-already-recorded"
        nav.close()
    assert sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_withdrawal WHERE id_order={oid}") == "1"
    accuses = attendre_mails(b["email"], n=len(msgs) + 1)
    nouveaux = [m for m in accuses if m["ID"] not in {x["ID"] for x in msgs}]
    ref = sql_val(f"SELECT reference FROM ps_orders WHERE id_order={oid}")
    assert len(nouveaux) == 1 and ref in mail(nouveaux[0]["ID"])["Text"], [m["Subject"] for m in accuses]


def test_formulaire_retractation_protege_contre_csrf(boutique):
    b = acheteur()
    s, fin = commander(boutique, b)
    oid = id_commande(fin["order"]["id"])
    url = php(f"echo Module::getInstanceByName('ucpbridge')->withdrawalUrl({oid});")
    r = appel("POST", url, form={"csrf": "faux"})
    assert sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_withdrawal WHERE id_order={oid}") == "0"
