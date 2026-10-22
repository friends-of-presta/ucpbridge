"""Issue #11 : mise à jour sans perte depuis la 0.1.2 publiée (scripts upgrade/), par le gestionnaire de modules.

Scénario réel : la 0.1.2 (commit a6d66f9, extraite de l'historique git) est installée sur une base vierge, on y crée
des sessions, des commandes, une preuve, une rétractation, des jetons OAuth, puis les sources courantes remplacent
les fichiers et PrestaShop lance la mise à jour (`prestashop:module upgrade`). Tout doit être conservé et marcher, le
schéma obtenu doit être celui d'une installation fraîche, et le script doit pouvoir être rejoué.

Le module reste ensuite installé dans sa version courante (avec les données de la 0.1.2) : les tests suivants
tournent donc aussi sur une boutique mise à jour.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import os
import re
import subprocess

import pytest

from conftest import (BANC, BASE, CLIENT_ID, PS, appel, cle_api, config, id_commande, jeton_client, php, secret_client_test, set_config,
                      sql, sql_val)

VERSION_PUBLIEE = "a6d66f9b16357c1c5301658b93a29ed61580f482"  # 0.1.2-beta, première publication
TABLES_DONNEES = {
    "ps_ucpbridge_session": "id_session", "ps_ucpbridge_proof": "id_proof", "ps_ucpbridge_withdrawal": "id_order",
    "ps_ucpbridge_payment": "id_payment", "ps_ucpbridge_oauth_code": "code_hash", "ps_ucpbridge_oauth_token": "token_hash",
    "ps_ucpbridge_oauth_revocation": "client_id, id_customer",
}


def console(*args):
    return subprocess.run(["docker", "exec", "-u", "www-data", PS, "php", "bin/console", *args], capture_output=True, text=True)


def conteneur(commande):
    r = subprocess.run(["docker", "exec", PS, "sh", "-c", commande], capture_output=True, text=True)
    assert r.returncode == 0, r.stdout + r.stderr
    return r.stdout


def version_courante():
    src = open(os.path.join(BANC, "ucpbridge", "ucpbridge.php"), encoding="utf-8").read()
    return re.search(r"\$this->version = '([0-9.]+)'", src).group(1)


def schema():
    """Colonnes et index des tables du module (ce qu'une installation fraîche produit)."""
    cols = sql("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, 'NULL'), EXTRA "
               "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'ps\\_ucpbridge\\_%' "
               "ORDER BY TABLE_NAME, COLUMN_NAME")
    idx = sql("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) "
              "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'ps\\_ucpbridge\\_%' "
              "GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME")
    return cols, idx


def cles_config():
    return sql("SELECT name FROM ps_configuration WHERE name LIKE 'UCPBRIDGE\\_%' ORDER BY name").split()


def contenu(table):
    return sql(f"SELECT * FROM {table} ORDER BY {TABLES_DONNEES[table]}")


def version_publiee_disponible():
    """Le commit de la 0.1.2 doit être dans le clone ; un clone superficiel (intégration continue) le récupère seul."""
    if subprocess.run(["git", "-C", BANC, "cat-file", "-e", VERSION_PUBLIEE + "^{commit}"], capture_output=True).returncode != 0:
        r = subprocess.run(["git", "-C", BANC, "fetch", "--quiet", "--depth", "1", "origin", VERSION_PUBLIEE], capture_output=True, text=True)
        assert r.returncode == 0, "commit de la 0.1.2 introuvable (git fetch origin " + VERSION_PUBLIEE + ") : " + r.stderr


def base_vierge():
    """Module désinstallé et aucune table du module en base (même celles qu'une désinstallation conserve)."""
    r = console("prestashop:module", "uninstall", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    for t in sql("SHOW TABLES LIKE 'ps\\_ucpbridge\\_%'").split():
        sql(f"DROP TABLE {t}")


def installer_sources(source):
    """Remplace les fichiers du module dans la boutique (sans le désinstaller) : source = 'git' (0.1.2) ou 'courant'."""
    conteneur("rm -rf /var/www/html/modules/ucpbridge")
    if source == "git":
        archive = subprocess.run(["git", "-C", BANC, "archive", VERSION_PUBLIEE, "ucpbridge"], capture_output=True, check=True).stdout
        r = subprocess.run(["docker", "exec", "-i", PS, "tar", "-x", "-C", "/var/www/html/modules"], input=archive, capture_output=True)
        assert r.returncode == 0, r.stderr
    else:
        subprocess.run(["docker", "cp", os.path.join(BANC, "ucpbridge"), f"{PS}:/var/www/html/modules/ucpbridge"], check=True,
                       capture_output=True)
    conteneur("rm -rf /var/www/html/modules/ucpbridge/tests && chown -R www-data:www-data /var/www/html/modules/ucpbridge "
              "&& rm -rf /var/www/html/var/cache/*")


def graine_012():
    """Jeu de démo de la 0.1.2 (celui du commit publié), lancé contre le module 0.1.2."""
    graine = subprocess.run(["git", "-C", BANC, "show", f"{VERSION_PUBLIEE}:seed-demo.php"], capture_output=True, text=True, check=True).stdout
    r = subprocess.run(["docker", "exec", "-i", "-u", "www-data", PS, "php"], input=graine, capture_output=True, text=True)
    assert r.returncode == 0 and "Client OAuth" in r.stdout, r.stdout + r.stderr


@pytest.fixture(scope="module")
def mise_a_jour(boutique, oauth_meta):
    """Installe la 0.1.2, y crée des données, puis met à jour vers les sources courantes. Renvoie le contexte."""
    version_publiee_disponible()
    # 0. Référence : installation fraîche des sources courantes sur une base vierge.
    base_vierge()
    r = console("prestashop:module", "install", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    ctx = {"schema_frais": schema(), "config_fraiche": cles_config()}
    # 1. Boutique en 0.1.2 sur une base vierge (aucune table du module).
    base_vierge()
    installer_sources("git")
    r = console("prestashop:module", "install", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    assert sql_val("SELECT version FROM ps_module WHERE name = 'ucpbridge'") == "0.1.2"
    graine_012()
    # La clé API reste celle du banc (les tests suivants la réutilisent) : un marchand garde la sienne de même.
    set_config("UCPBRIDGE_API_KEY", boutique.cle)

    # 2. Données de la 0.1.2 : commande payée (preuve, journal des paiements), session en cours, panier, rétractation,
    #    jetons OAuth et commande passée au nom du client relié.
    s = boutique.session_prete()
    r = boutique.payer_mock(s["id"])
    assert r.code == 200 and r.doc["status"] == "completed", r.texte[:500]
    ctx["commande"] = r.doc["order"]["id"]
    ctx["oid"] = id_commande(ctx["commande"])
    ctx["en_cours"] = boutique.session_prete()["id"]
    r = boutique.post("/carts", {"line_items": [{"item": {"id": "demo_1-M-BLANC"}, "quantity": 2}], "currency": "EUR"})
    assert r.code == 201, r.texte[:300]
    ctx["panier"] = r.doc["id"]
    assert php(f"Module::getInstanceByName('ucpbridge'); echo UcpBridgeProof::recordWithdrawal({ctx['oid']}, 'agent@contrib.test', '192.0.2.10') ? 'ok' : 'ko';").endswith("ok")
    ctx["lien_retractation"] = php(f"echo Module::getInstanceByName('ucpbridge')->withdrawalUrl({ctx['oid']});").splitlines()[-1]
    ctx["jetons"] = jeton_client(oauth_meta)
    acces = ctx["jetons"]["access_token"]
    s = boutique.session_prete(buyer=None, jeton=acces)
    r = boutique.payer_mock(s["id"], jeton=acces)
    assert r.doc["status"] == "completed", r.texte[:500]
    ctx["commande_oauth"] = r.doc["order"]["id"]
    ctx["config_012"] = {k: config(k) for k in ("UCPBRIDGE_API_KEY", "UCPBRIDGE_LINK_SECRET", "UCPBRIDGE_MOCK_SECRET",
                                                 "UCPBRIDGE_OAUTH_CLIENTS", "UCPBRIDGE_STRIPE_KEY", "UCPBRIDGE_CMS_TERMS")}
    ctx["donnees_012"] = {t: contenu(t) for t in TABLES_DONNEES}
    for t in ("ps_ucpbridge_session", "ps_ucpbridge_proof", "ps_ucpbridge_withdrawal", "ps_ucpbridge_payment", "ps_ucpbridge_oauth_token"):
        assert ctx["donnees_012"][t], f"aucune donnée 0.1.2 dans {t}"

    # 3. Mise à jour par le gestionnaire de modules : nouveaux fichiers, puis upgrade (sans désinstallation).
    installer_sources("courant")
    ctx["upgrade"] = console("prestashop:module", "upgrade", "ucpbridge")
    return ctx


def test_mise_a_jour_declenchee_par_prestashop(mise_a_jour):
    r = mise_a_jour["upgrade"]
    assert r.returncode == 0, r.stdout + r.stderr
    assert version_courante() != "0.1.2"
    assert sql_val("SELECT CONCAT(version, ' ', active) FROM ps_module WHERE name = 'ucpbridge'") == version_courante() + " 1"
    assert conteneur("ls /var/www/html/modules/ucpbridge/upgrade").split().count(f"upgrade-{version_courante()}.php") == 1


def test_aucune_donnee_perdue(mise_a_jour):
    for table, avant in mise_a_jour["donnees_012"].items():
        assert contenu(table) == avant, f"{table} modifiée par la mise à jour"


def test_schema_identique_a_une_installation_fraiche(mise_a_jour):
    assert schema() == mise_a_jour["schema_frais"]
    assert cles_config() == mise_a_jour["config_fraiche"]


def test_configuration_conservee(mise_a_jour):
    avant = dict(mise_a_jour["config_012"])
    # Clé unique de la 0.1.2 devenue la plateforme « default » (UCPBRIDGE_API_KEYS) : la plateforme garde son accès.
    assert cle_api() == avant.pop("UCPBRIDGE_API_KEY") and config("UCPBRIDGE_API_KEY") == ""
    for k, v in avant.items():
        assert config(k) == v, k


def test_tout_fonctionne_apres_la_mise_a_jour(boutique, oauth_meta, mise_a_jour):
    # Commandes de la 0.1.2 lisibles par leur appelant, preuve affichée sur la fiche commande.
    r = boutique.get(f"/orders/{mise_a_jour['commande']}")
    assert r.code == 200 and r.doc["id"] == mise_a_jour["commande"], r.texte[:300]
    html = php(f"echo Module::getInstanceByName('ucpbridge')->hookDisplayAdminOrderMain(['id_order' => {mise_a_jour['oid']}]);")
    assert "Preuve UCP" in html and "demandée le" in html
    # Lien de rétractation signé avant la mise à jour : toujours valide, la demande est retrouvée.
    r = appel("GET", mise_a_jour["lien_retractation"])
    assert r.code == 200 and "déjà été enregistrée" in r.texte, r.texte[:300]
    # Session en cours et panier de la 0.1.2 : finalisables après la mise à jour.
    assert boutique.get(f"/carts/{mise_a_jour['panier']}").code == 200
    r = boutique.payer_mock(mise_a_jour["en_cours"])
    assert r.code == 200 and r.doc["status"] == "completed", r.texte[:500]
    # Jetons OAuth émis par la 0.1.2 : jeton d'accès accepté, jeton de rafraîchissement échangeable.
    acces = mise_a_jour["jetons"]["access_token"]
    assert boutique.get(f"/orders/{mise_a_jour['commande_oauth']}", jeton=acces).code == 200
    r = appel("POST", oauth_meta["token_endpoint"], form={"grant_type": "refresh_token",
                                                          "refresh_token": mise_a_jour["jetons"]["refresh_token"]},
              basic=(CLIENT_ID, secret_client_test()))
    assert r.code == 200 and r.doc["access_token"].startswith("ucpat_"), r.texte
    # Nouvelle commande de bout en bout.
    s = boutique.session_prete()
    assert boutique.payer_mock(s["id"]).doc["status"] == "completed"
    assert appel("GET", f"{BASE}/.well-known/ucp").code == 200


def test_clients_invites_de_la_012_repris_pour_la_purge(mise_a_jour):
    """Les invités créés par les sessions de la 0.1.2 sont enregistrés comme créés par le module (purge RGPD, #13)."""
    invites = set(sql("SELECT DISTINCT c.id_customer FROM ps_ucpbridge_session s JOIN ps_cart c ON c.id_cart = s.id_cart "
                      "JOIN ps_customer cu ON cu.id_customer = c.id_customer WHERE cu.is_guest = 1").split())
    assert invites
    assert invites <= set(sql("SELECT id_customer FROM ps_ucpbridge_customer").split())


def test_script_rejouable(mise_a_jour):
    donnees = {t: contenu(t) for t in TABLES_DONNEES}
    fichier = f"/var/www/html/modules/ucpbridge/upgrade/upgrade-{version_courante()}.php"
    fonction = "upgrade_module_" + version_courante().replace(".", "_")
    for _ in range(2):
        out = php(f"$m = Module::getInstanceByName('ucpbridge'); require '{fichier}'; echo {fonction}($m) ? 'REJOUE_OK' : 'REJOUE_KO';")
        assert out.endswith("REJOUE_OK"), out[-500:]
    assert schema() == mise_a_jour["schema_frais"]
    assert {t: contenu(t) for t in TABLES_DONNEES} == donnees
    # Une deuxième mise à jour par PrestaShop (déjà à jour) ne casse rien.
    r = console("prestashop:module", "upgrade", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    assert sql_val("SELECT active FROM ps_module WHERE name = 'ucpbridge'") == "1"


def test_ajout_de_colonne_en_place_et_rejouable():
    """Mécanisme des colonnes ajoutées (Ucpbridge::ADDED_COLUMNS) sur une vraie table : lignes intactes, second passage neutre."""
    sql("DROP TABLE IF EXISTS ps_ucpbridge_test_schema")
    sql("CREATE TABLE ps_ucpbridge_test_schema (id INT PRIMARY KEY, nom VARCHAR(20) NOT NULL)")
    sql("INSERT INTO ps_ucpbridge_test_schema VALUES (1, 'avant')")
    try:
        code = ("Module::getInstanceByName('ucpbridge'); $c = ['ucpbridge_test_schema' => ['nom' => 'VARCHAR(20) NOT NULL', "
                "'essai' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'note' => 'TEXT NULL']]; "
                "echo UcpBridgeSchema::addMissingColumns($c) && UcpBridgeSchema::addMissingColumns($c) ? 'OK' : 'KO';")
        assert php(code).endswith("OK")
        assert sql("SELECT * FROM ps_ucpbridge_test_schema") == "1\tavant\t0\tNULL"
    finally:
        sql("DROP TABLE IF EXISTS ps_ucpbridge_test_schema")
