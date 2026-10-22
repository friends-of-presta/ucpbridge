"""Issue #12 : la désinstallation conserve par défaut le journal de preuve, les rétractations et le journal des
paiements ; suppression seulement sur choix explicite ; export CSV des preuves et rétractations dans le back-office.

Chaque désinstallation est suivie d'une réinstallation, du jeu de démo et du retour des secrets du banc (clés API,
secret des liens signés) : les tests suivants retrouvent la boutique dans l'état attendu.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import csv
import io
import json
import subprocess
import urllib.parse

import pytest

from conftest import BASE, PS, admin_dir, admin_login, config, id_commande, php, set_config, sql, sql_val

PREUVES = ["ps_ucpbridge_proof", "ps_ucpbridge_withdrawal", "ps_ucpbridge_payment"]
CLES = {"ps_ucpbridge_proof": "id_proof", "ps_ucpbridge_withdrawal": "id_order", "ps_ucpbridge_payment": "id_payment"}
ENTETE = ["id_commande", "reference_commande", "date_preuve", "session", "agent_ucp", "appelant", "ip_appel_api",
          "user_agent_appel_api", "version_cgv", "acceptation_cgv", "liens_legaux", "mentions", "totaux", "consentements",
          "retractation_date", "retractation_email", "retractation_ip", "retractation_accuse_envoye"]


def console(*args):
    return subprocess.run(["docker", "exec", "-u", "www-data", PS, "php", "bin/console", *args], capture_output=True, text=True)


def tables():
    return sorted(sql("SHOW TABLES LIKE 'ps\\_ucpbridge\\_%'").split())


def contenu(table):
    return sql(f"SELECT * FROM {table} ORDER BY {CLES[table]}")


def commande_avec_retractation(boutique, agent=None):
    s = boutique.session_prete()
    corps = {"payment": {"instruments": [boutique.instrument_mock()]}}
    r = boutique.post(f"/checkout-sessions/{s['id']}/complete", corps, entetes={"UCP-Agent": agent} if agent else None)
    assert r.code == 200 and r.doc["status"] == "completed", r.texte[:500]
    oid = id_commande(r.doc["order"]["id"])
    out = php(f"Module::getInstanceByName('ucpbridge'); echo UcpBridgeProof::recordWithdrawal({oid}, 'retractation@contrib.test', '198.51.100.7') ? 'ok' : 'ko';")
    assert out.endswith("ok"), out
    return oid


@pytest.fixture
def reinstallation():
    """Rend la boutique telle que la suite l'attend après une désinstallation : module installé, démo, mêmes secrets."""
    secrets = {k: config(k) for k in ("UCPBRIDGE_API_KEYS", "UCPBRIDGE_LINK_SECRET")}
    yield
    if sql_val("SELECT COUNT(*) FROM ps_module WHERE name = 'ucpbridge'") == "0":
        r = console("prestashop:module", "install", "ucpbridge")
        assert r.returncode == 0, r.stdout + r.stderr
    subprocess.run(["docker", "exec", PS, "sh", "-c", "rm -rf /var/www/html/var/cache/*"], check=True)
    r = subprocess.run(["docker", "exec", "-u", "www-data", PS, "php", "/tmp/seed-demo.php"], capture_output=True, text=True)
    assert r.returncode == 0, r.stdout + r.stderr
    for k, v in secrets.items():
        set_config(k, v)


def back_office(post=None):
    code = "Context::getContext()->employee = new Employee(1); $m = Module::getInstanceByName('ucpbridge');\n"
    if post is not None:
        code += ("$h = $m->getContent(); preg_match('/name=\"ucp_csrf\" value=\"([0-9a-f]+)\"/', $h, $t);\n"
                 f"$_POST = json_decode({json.dumps(json.dumps(post))}, true);\n"
                 "if (($_POST['ucp_csrf'] ?? '') === 'JETON') { $_POST['ucp_csrf'] = $t[1]; }\n")
    return php(code + "echo $m->getContent();")


def test_desinstallation_conserve_preuves_retractations_et_paiements(boutique, reinstallation):
    oid = commande_avec_retractation(boutique)
    avant = {t: contenu(t) for t in PREUVES}
    assert all(avant.values())
    r = console("prestashop:module", "uninstall", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    # Seules les tables de preuve restent (et la liste des invités créés par le module, pour la purge RGPD) ;
    # configuration et autres tables supprimées comme avant.
    assert tables() == sorted(PREUVES + ["ps_ucpbridge_customer"])
    assert {t: contenu(t) for t in PREUVES} == avant
    assert sql_val("SELECT COUNT(*) FROM ps_configuration WHERE name LIKE 'UCPBRIDGE\\_%'") == "0"
    # Réinstallation : tout est retrouvé, la preuve reste affichée sur la fiche commande.
    r = console("prestashop:module", "install", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    assert {t: contenu(t) for t in PREUVES} == avant
    html = php(f"echo Module::getInstanceByName('ucpbridge')->hookDisplayAdminOrderMain(['id_order' => {oid}]);")
    assert "Preuve UCP" in html and "demandée le" in html


def test_suppression_seulement_sur_choix_explicite(boutique, reinstallation):
    commande_avec_retractation(boutique)
    assert config("UCPBRIDGE_UNINSTALL_PURGE") == "0"  # par défaut : conservation
    html = back_office()
    assert 'name="UCPBRIDGE_UNINSTALL_PURGE"' in html and "irréversible" in html
    # Formulaire sans le jeton du module : choix refusé.
    back_office({"submitUcpbridge": "1", "ucp_csrf": "faux", "UCPBRIDGE_UNINSTALL_PURGE": "1"})
    assert config("UCPBRIDGE_UNINSTALL_PURGE") == "0"
    back_office({"submitUcpbridge": "1", "ucp_csrf": "JETON", "UCPBRIDGE_UNINSTALL_PURGE": "1"})
    assert config("UCPBRIDGE_UNINSTALL_PURGE") == "1"
    r = console("prestashop:module", "uninstall", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    assert tables() == []
    r = console("prestashop:module", "install", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    assert all(contenu(t) == "" for t in PREUVES)
    assert config("UCPBRIDGE_UNINSTALL_PURGE") == "0"  # le choix ne survit pas à la réinstallation


def lire_csv(texte):
    assert texte.startswith("﻿"), "BOM UTF-8 attendu (ouverture directe dans Excel)"
    return list(csv.reader(io.StringIO(texte[1:]), delimiter=";"))


def test_export_csv_des_preuves_et_retractations(boutique):
    oid = commande_avec_retractation(boutique, agent='=HYPERLINK("https://exemple.test";"clic")')
    ref = sql_val(f"SELECT reference FROM ps_orders WHERE id_order = {oid}")
    texte = php("Module::getInstanceByName('ucpbridge'); $f = fopen('php://output', 'w'); UcpBridgeExport::write($f); fclose($f);")
    lignes = lire_csv(texte)
    assert lignes[0] == ENTETE
    assert len(lignes) - 1 == int(sql_val("SELECT COUNT(*) FROM ps_ucpbridge_proof p LEFT JOIN ps_ucpbridge_withdrawal w USING (id_order)")) \
        + int(sql_val("SELECT COUNT(*) FROM ps_ucpbridge_withdrawal w LEFT JOIN ps_ucpbridge_proof p USING (id_order) WHERE p.id_order IS NULL"))
    ligne = dict(zip(ENTETE, next(l for l in lignes if l[0] == str(oid))))
    assert ligne["reference_commande"] == ref
    assert ligne["retractation_email"] == "retractation@contrib.test" and ligne["retractation_ip"] == "198.51.100.7"
    assert json.loads(ligne["liens_legaux"])[0]["type"] == "terms_of_service"
    assert json.loads(ligne["mentions"])[0]["code"] == "withdrawal_right"
    # Injection de formule neutralisée (en-tête UCP-Agent contrôlé par l'agent).
    assert ligne["agent_ucp"].startswith("'=HYPERLINK(")


def test_export_depuis_le_back_office(boutique):
    from playwright.sync_api import sync_playwright
    commande_avec_retractation(boutique)
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(locale="fr-FR", viewport={"width": 1280, "height": 1000}, accept_downloads=True)
        admin_login(page)
        page.goto(f"{BASE}/{admin_dir()}/index.php?controller=AdminModules&configure=ucpbridge")
        if "compromised" in page.url:
            page.click("text=Oui, je comprends les risques")
        lien = page.locator("a#ucpbridge-export")
        lien.wait_for(timeout=15000)
        with page.expect_download() as dl:
            lien.click()
        fichier = dl.value
        assert fichier.suggested_filename.startswith("ucpbridge-preuves-") and fichier.suggested_filename.endswith(".csv")
        lignes = lire_csv(open(fichier.path(), encoding="utf-8").read())
        assert lignes[0] == ENTETE and len(lignes) > 1
        # Lien sans le jeton du module : aucun export.
        url = lien.get_attribute("href")
        q = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(url).query))
        q["ucp_csrf"] = "faux"
        r = page.request.get(url.split("?")[0] + "?" + urllib.parse.urlencode(q))
        assert "text/csv" not in r.headers.get("content-type", "") and "Jeton de sécurité invalide" in r.text()
        nav.close()
