"""Traductions (#15) : boutique en anglais (langue par défaut) puis en français, mêmes parcours. Les messages lisibles
suivent la langue, les codes UCP renvoyés aux agents ne changent pas. C'est le seul fichier de tests qui lit des
libellés : il vérifie justement la langue affichée.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import re
import uuid

import pytest

from conftest import BASE, DEST_FR, M_BLANC, attendre_mails, config, id_commande, mail, php, set_config, sql_val


def id_langue(iso):
    return int(sql_val(f"SELECT id_lang FROM ps_lang WHERE iso_code = '{iso}'"))


@pytest.fixture
def langue():
    """Change la langue par défaut de la boutique le temps d'un test (requêtes sans cookie : langue par défaut)."""
    avant = config("PS_LANG_DEFAULT")

    def choisir(iso):
        set_config("PS_LANG_DEFAULT", id_langue(iso))
    yield choisir
    set_config("PS_LANG_DEFAULT", avant)


def acheteur():
    return {"email": f"langue-{uuid.uuid4().hex[:10]}@contrib.test", "first_name": "Alex", "last_name": "Martin"}


def messages(doc):
    return {(m["code"], m.get("path", "")): m["content"] for m in doc.get("messages", [])}


def parcours_api(boutique):
    """Panier, caisse incomplète puis refus : messages, totaux et codes renvoyés à l'agent."""
    panier = boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 1}], "context": {"address_country": "FR"}}).doc
    caisse = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}]}).doc
    caisse = boutique.put(f"/checkout-sessions/{caisse['id']}", {"fulfillment": {"methods": [
        {"type": "shipping", "destinations": [DEST_FR], "selected_destination_id": DEST_FR["id"]}]}}).doc
    variante = boutique.post("/carts", {"line_items": [{"item": {"id": "demo_1"}, "quantity": 1}]}).doc
    inconnu = boutique.get("/checkout-sessions/chk_inconnue").doc
    return {
        "panier": messages(panier), "caisse": messages(caisse), "variante": messages(variante), "inconnu": messages(inconnu),
        "totaux": [t["display_text"] for t in caisse["totals"]],
        "totaux_panier": [t["display_text"] for t in panier["totals"]],
    }


def test_api_dans_la_langue_de_la_boutique_codes_inchanges(boutique, langue):
    langue("en")
    en = parcours_api(boutique)
    langue("fr")
    fr = parcours_api(boutique)
    for cle in ("panier", "caisse", "variante", "inconnu"):
        assert en[cle] and set(en[cle]) == set(fr[cle]), cle  # mêmes codes et mêmes chemins
        assert en[cle] != fr[cle], cle  # textes traduits
    assert en["caisse"][("missing", "$.buyer.email")] == "Buyer e-mail required"
    assert fr["caisse"][("missing", "$.buyer.email")] == "E-mail de l'acheteur requis"
    assert en["panier"][("withdrawal_right", "")].startswith("Right of withdrawal:")
    assert fr["panier"][("withdrawal_right", "")].startswith("Droit de rétractation :")
    assert en["totaux"][-1] == "Total incl. tax" and fr["totaux"][-1] == "Total TTC"
    assert len(en["totaux_panier"]) == len(fr["totaux_panier"])


def test_erreur_d_authentification_traduite_code_inchange(boutique, langue):
    from conftest import appel
    langue("en")
    en = appel("GET", f"{BASE}/ucp/v1/carts/x", jeton="ucpk_faux")
    langue("fr")
    fr = appel("GET", f"{BASE}/ucp/v1/carts/x", jeton="ucpk_faux")
    assert en.code == fr.code == 401 and en.doc["code"] == fr.doc["code"] == "unauthorized"
    assert en.doc["content"] != fr.doc["content"] and "API key" in en.doc["content"]


def configuration(iso):
    return php(f"$c = Context::getContext(); $c->employee = new Employee(1); $c->language = new Language({id_langue(iso)});\n"
               "echo Module::getInstanceByName('ucpbridge')->getContent();")


def test_configuration_dans_la_langue_de_l_employe():
    en = configuration("en")
    fr = configuration("fr")
    assert "Platforms (API keys" in en and "Plateformes (clés API" not in en
    assert "Plateformes (clés API" in fr and "Platforms (API keys" not in fr
    assert "Legal links" in en and "Liens légaux" in fr
    # Mêmes formulaires, mêmes champs, quel que soit la langue.
    champs = lambda h: sorted(set(re.findall(r'name="([A-Za-z_]+)"', h)))
    assert champs(en) == champs(fr)


def test_fiche_commande_et_pages_en_anglais(boutique, langue):
    from playwright.sync_api import sync_playwright
    langue("en")
    b = acheteur()
    s = boutique.session_prete(buyer=b)
    fin = boutique.payer_mock(s["id"]).doc
    assert fin["status"] == "completed"
    oid = id_commande(fin["order"]["id"])
    assert sql_val(f"SELECT l.iso_code FROM ps_orders o JOIN ps_lang l USING (id_lang) WHERE o.id_order = {oid}") == "en"
    # Bloc de preuve du back-office dans la langue de l'employé.
    proof = php(f"Context::getContext()->language = new Language({id_langue('en')}); "
                f"echo Module::getInstanceByName('ucpbridge')->hookDisplayAdminOrderMain(['id_order' => {oid}]);")
    assert "UCP evidence" in proof and "Preuve UCP" not in proof
    # E-mail de confirmation : bloc légal en anglais, lien de rétractation.
    msgs = attendre_mails(b["email"])
    retrait = re.compile(r'href="([^"]*controller=withdraw[^"]*|[^"]*/withdraw\?[^"]*)"')
    conf = [mail(m["ID"])["HTML"] for m in msgs]
    html = next((h for h in conf if retrait.search(h)), "")
    assert html, "lien de rétractation absent"
    assert "Legal information" in html and "Informations légales" not in html
    url = retrait.search(html).group(1).replace("&amp;", "&")
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page()
        page.goto(url)
        assert page.get_attribute("html", "lang") == "en"
        assert "Withdraw from this order" in page.inner_text("body")
        page.click("button[type=submit]")
        assert page.get_attribute("main", "data-ucp-page") == "withdrawal-recorded"
        assert "acknowledgement" in page.inner_text("body").lower()
        nav.close()
    accuses = attendre_mails(b["email"], n=len(msgs) + 1)
    accuse = [m for m in accuses if m["ID"] not in {x["ID"] for x in msgs}][0]
    assert accuse["Subject"].endswith("Acknowledgement of your withdrawal request")  # préfixé du nom de la boutique
    texte = mail(accuse["ID"])["Text"]
    assert texte.splitlines()[2].startswith("Hello") and "rétractation" not in texte


def test_lien_mes_acces_agents_traduit(langue):
    langue("en")
    en = php(f"Context::getContext()->language = new Language({id_langue('en')}); "
             "echo Module::getInstanceByName('ucpbridge')->hookDisplayCustomerAccount([]);")
    assert 'id="ucpbridge-access-link"' in en and "My agent access" in en
