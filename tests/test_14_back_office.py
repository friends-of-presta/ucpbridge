"""Configuration du module par le navigateur (#17) : chaque fonction de la page de configuration, depuis l'interface,
avec le jeton anti-CSRF du module. Les assertions portent sur la configuration enregistrée et sur des sélecteurs
stables (noms de champs, identifiants), jamais sur des libellés.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import json
import re

import pytest

from conftest import BASE, CLIENT_ID, admin_dir, admin_login, cle_api, config, set_config, sql
from test_04_relecture import id_client_demo, jeton_direct

def ouvrir_config(page):
    """Lien sans jeton d'administration : PrestaShop demande confirmation, sur une page à part en 9 (URL
    « compromised »), dans la page elle-même en 8. Le lien de confirmation est cherché dans les deux cas."""
    page.goto(f"{BASE}/{admin_dir()}/index.php?controller=AdminModules&configure=ucpbridge")
    risques = page.locator("a, button").filter(has_text=re.compile("comprends les risques|understand the risks", re.I))
    if risques.count():
        with page.expect_navigation(wait_until="load"):
            risques.first.click()
    page.wait_for_selector("input[name=ucp_csrf]", state="attached")


def cocher(page, nom, valeur):
    """Case à cocher ou interrupteur oui/non (boutons radio 1/0) : même résultat envoyé."""
    page.evaluate("""([nom, valeur]) => {
        const c = document.querySelector(`input[type=checkbox][name="${nom}"]`);
        if (c) { c.checked = valeur; return; }
        const r = document.querySelector(`input[type=radio][name="${nom}"][value="${valeur ? 1 : 0}"]`);
        r.checked = true;
    }""", [nom, valeur])


def soumettre(page, bouton, formulaire=None):
    zone = page.locator(formulaire) if formulaire else page
    # Attente du rechargement, pas du repos réseau : certains tableaux de bord interrogent le serveur en continu.
    with page.expect_navigation(wait_until="load"):
        zone.locator(f"button[name={bouton}]").first.click()
    page.wait_for_selector("input[name=ucp_csrf]", state="attached")


@pytest.fixture
def navigateur():
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(locale="fr-FR", viewport={"width": 1280, "height": 1000})
        page.on("dialog", lambda d: d.accept())
        admin_login(page)
        ouvrir_config(page)
        yield page
        nav.close()


@pytest.fixture
def config_sauvegardee():
    cles = ["UCPBRIDGE_MOCK_SECRET", "UCPBRIDGE_STRIPE_KEY", "UCPBRIDGE_STRIPE_PK", "UCPBRIDGE_GPAY_MERCHANT_ID", "UCPBRIDGE_TERMS_REVIEW",
            "UCPBRIDGE_UNINSTALL_PURGE", "UCPBRIDGE_CMS_TERMS", "UCPBRIDGE_CMS_PRIVACY", "UCPBRIDGE_CMS_REFUND", "UCPBRIDGE_CMS_SHIPPING",
            "UCPBRIDGE_RETENTION_SESSIONS", "UCPBRIDGE_RETENTION_GUESTS", "UCPBRIDGE_RETENTION_OAUTH", "UCPBRIDGE_RETENTION_PAYMENTS",
            "UCPBRIDGE_RETENTION_PROOFS"]
    avant = {k: valeur(k) for k in cles}
    yield
    for k, v in avant.items():
        set_config(k, v)


def valeur(cle):
    """Valeur enregistrée ; une valeur vide est stockée NULL par PrestaShop."""
    v = config(cle)
    return "" if v == "NULL" else v


def test_reglages_enregistres_depuis_le_formulaire(navigateur, config_sauvegardee):
    page = navigateur
    secret = valeur("UCPBRIDGE_MOCK_SECRET")
    page.fill("input[name=UCPBRIDGE_MOCK_SECRET]", secret + "-bo")
    page.fill("input[name=UCPBRIDGE_STRIPE_PK]", "pk_test_bo")
    page.fill("input[name=UCPBRIDGE_GPAY_MERCHANT_ID]", "BCR2DN4T-bo")
    page.fill("input[name=UCPBRIDGE_STRIPE_KEY]", "sk_test_bo_secret")
    page.select_option("select[name=UCPBRIDGE_CMS_PRIVACY]", "2")
    page.select_option("select[name=UCPBRIDGE_CMS_SHIPPING]", "0")
    cocher(page, "UCPBRIDGE_TERMS_REVIEW", True)
    cocher(page, "UCPBRIDGE_UNINSTALL_PURGE", True)
    soumettre(page, "submitUcpbridge")
    assert config("UCPBRIDGE_MOCK_SECRET") == secret + "-bo"
    assert config("UCPBRIDGE_STRIPE_PK") == "pk_test_bo" and config("UCPBRIDGE_GPAY_MERCHANT_ID") == "BCR2DN4T-bo"
    assert config("UCPBRIDGE_STRIPE_KEY") == "sk_test_bo_secret"
    assert config("UCPBRIDGE_CMS_PRIVACY") == "2" and config("UCPBRIDGE_CMS_SHIPPING") == "0"
    assert config("UCPBRIDGE_TERMS_REVIEW") == "1" and config("UCPBRIDGE_UNINSTALL_PURGE") == "1"
    # Clé secrète jamais réaffichée ; champ laissé vide : clé conservée.
    assert "sk_test_bo_secret" not in page.content()
    assert page.input_value("input[name=UCPBRIDGE_STRIPE_KEY]") == ""
    cocher(page, "UCPBRIDGE_TERMS_REVIEW", False)
    cocher(page, "UCPBRIDGE_UNINSTALL_PURGE", False)
    soumettre(page, "submitUcpbridge")
    assert config("UCPBRIDGE_STRIPE_KEY") == "sk_test_bo_secret"
    assert config("UCPBRIDGE_TERMS_REVIEW") == "0" and config("UCPBRIDGE_UNINSTALL_PURGE") == "0"
    # Les valeurs enregistrées sont réaffichées.
    assert page.input_value("input[name=UCPBRIDGE_STRIPE_PK]") == "pk_test_bo"
    assert page.input_value("select[name=UCPBRIDGE_CMS_PRIVACY]") == "2"
    # « - » efface la clé secrète.
    page.fill("input[name=UCPBRIDGE_STRIPE_KEY]", "-")
    soumettre(page, "submitUcpbridge")
    assert valeur("UCPBRIDGE_STRIPE_KEY") == ""


def test_plateformes_ajout_regeneration_suppression_par_l_interface(navigateur):
    page = navigateur
    try:
        page.fill("input[name=api_key_id]:not([type=hidden])", "bo-ui")
        page.fill("input[name=api_key_label]", "Plateforme BO")
        soumettre(page, "submitUcpbridgeApiKeyAdd")
        cle = cle_api("bo-ui")
        assert cle.startswith("ucpk_") and cle in page.content()
        ligne = "form:has(input[type=hidden][name=api_key_id][value=bo-ui])"
        soumettre(page, "submitUcpbridgeApiKeyRegen", ligne)
        nouvelle = cle_api("bo-ui")
        assert nouvelle.startswith("ucpk_") and nouvelle != cle and nouvelle in page.content()
        soumettre(page, "submitUcpbridgeApiKeyDelete", ligne)
        assert cle_api("bo-ui") == ""
        assert page.locator(ligne).count() == 0
    finally:
        cles = json.loads(config("UCPBRIDGE_API_KEYS") or "{}")
        if "bo-ui" in cles:
            del cles["bo-ui"]
            set_config("UCPBRIDGE_API_KEYS", json.dumps(cles))
    assert cle_api() != ""


def test_durees_simulation_et_rapprochement_par_l_interface(navigateur, config_sauvegardee):
    page = navigateur
    page.fill("input[name=UCPBRIDGE_RETENTION_SESSIONS]", "61")
    soumettre(page, "submitUcpbridgeRetention")
    assert config("UCPBRIDGE_RETENTION_SESSIONS") == "61"
    assert page.input_value("input[name=UCPBRIDGE_RETENTION_SESSIONS]") == "61"
    soumettre(page, "submitUcpbridgePurgeDry")
    assert page.locator("#ucpbridge-purge-report li").count() >= 10
    page.fill("input[name=reconcile_minutes]", "30")
    soumettre(page, "submitUcpbridgeReconcile")
    assert page.locator(".alert-success").count() >= 1


def test_acces_agents_revocation_par_l_interface(navigateur, boutique):
    page = navigateur
    acces = jeton_direct()
    ouvrir_config(page)
    ligne = f"form:has(input[name=revoke_client][value='{CLIENT_ID}'])"
    assert page.locator(ligne).count() >= 1
    soumettre(page, "submitUcpbridgeRevoke", ligne)
    assert boutique.get("/orders/ord_000000000000000000000000", jeton=acces).code == 401
    assert sql("SELECT COUNT(*) FROM ps_ucpbridge_oauth_token WHERE client_id = '" + CLIENT_ID + "' AND id_customer = "
               + str(id_client_demo()) + " AND revoked = 0 AND expires_at > NOW()") == "0"


def test_jeton_csrf_faux_refuse_pour_chaque_formulaire(navigateur, config_sauvegardee):
    page = navigateur
    for bouton, champ, valeur, cle in [("submitUcpbridge", "input[name=UCPBRIDGE_STRIPE_PK]", "pk_test_faux", "UCPBRIDGE_STRIPE_PK"),
                                       ("submitUcpbridgeRetention", "input[name=UCPBRIDGE_RETENTION_GUESTS]", "77", "UCPBRIDGE_RETENTION_GUESTS")]:
        avant = config(cle)
        page.fill(champ, valeur)
        page.evaluate("document.querySelectorAll('input[name=ucp_csrf]').forEach(i => i.value = 'faux')")
        soumettre(page, bouton)
        assert config(cle) == avant
        assert page.locator(".alert-danger").count() >= 1
