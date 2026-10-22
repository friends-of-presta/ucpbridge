"""Installation, désinstallation propre, réinstallation du module (puis rechargement du jeu de démo).

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import subprocess

from conftest import BASE, PS, appel, cle_api, config, sql, sql_val

TABLES = ["ucpbridge_session", "ucpbridge_idempotency", "ucpbridge_oauth_code", "ucpbridge_oauth_token",
          "ucpbridge_proof", "ucpbridge_withdrawal", "ucpbridge_payment", "ucpbridge_oauth_revocation"]
PREUVES = ["ucpbridge_proof", "ucpbridge_withdrawal", "ucpbridge_payment"]
HOOKS = ["moduleRoutes", "actionHtaccessCreate", "displayAdminOrderMain", "actionEmailAddAfterContent",
         "actionGetExtraMailTemplateVars", "displayCustomerAccount"]


def console(*args):
    return subprocess.run(["docker", "exec", "-u", "www-data", PS, "php", "bin/console", *args], capture_output=True, text=True)


def etat():
    tables = sql("SHOW TABLES LIKE 'ps_ucpbridge%'").split()
    nb_config = int(sql_val("SELECT COUNT(*) FROM ps_configuration WHERE name LIKE 'UCPBRIDGE%'"))
    hooks = sql("SELECT h.name FROM ps_hook_module hm JOIN ps_hook h USING (id_hook) JOIN ps_module m USING (id_module) "
                "WHERE m.name='ucpbridge'").split()
    hook_ext = sql_val("SELECT COUNT(*) FROM ps_hook WHERE name='actionUcpBridgeRegisterPaymentProviders'")
    htaccess = subprocess.run(["docker", "exec", PS, "grep", "-c", "ucpbridge:begin", "/var/www/html/.htaccess"],
                              capture_output=True, text=True).stdout.strip()
    return tables, nb_config, hooks, hook_ext, htaccess


def test_desinstallation_puis_reinstallation():
    r = console("prestashop:module", "uninstall", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    tables, nb_config, hooks, hook_ext, htaccess = etat()
    # Journal de preuve, rétractations et journal des paiements conservés par défaut (#12) ; tout le reste supprimé.
    assert sorted(tables) == sorted("ps_" + t for t in PREUVES)
    assert nb_config == 0 and hooks == [] and hook_ext == "0" and htaccess == "0"
    assert sql_val("SELECT COUNT(*) FROM ps_module WHERE name='ucpbridge'") == "0"
    assert appel("GET", f"{BASE}/.well-known/ucp").code in (404, 302, 301)

    r = console("prestashop:module", "install", "ucpbridge")
    assert r.returncode == 0, r.stdout + r.stderr
    tables, nb_config, hooks, hook_ext, htaccess = etat()
    assert sorted(tables) == sorted("ps_" + t for t in TABLES)
    assert sorted(hooks) == sorted(HOOKS)
    assert hook_ext == "1" and htaccess == "1"
    # Secrets générés à l'installation, jamais livrés avec le module.
    assert cle_api().startswith("ucpk_") and len(config("UCPBRIDGE_LINK_SECRET")) == 64
    assert config("UCPBRIDGE_API_KEY") == ""  # clé unique des versions précédentes : plus utilisée
    vide = ("", "NULL")
    assert config("UCPBRIDGE_MOCK_SECRET") in vide and config("UCPBRIDGE_STRIPE_KEY") in vide
    assert config("UCPBRIDGE_OAUTH_CLIENTS") == "{}"
    assert config("UCPBRIDGE_TERMS_REVIEW") == "0" and config("UCPBRIDGE_CMS_TERMS") == "3"
    r = appel("GET", f"{BASE}/.well-known/ucp")
    assert r.code == 200
    # Sans configuration de démo : aucun gestionnaire de paiement annoncé.
    assert r.doc["ucp"]["payment_handlers"] == {}

    subprocess.run(["docker", "exec", PS, "sh", "-c", "rm -rf /var/www/html/var/cache/*"], check=True)
    r = subprocess.run(["docker", "exec", "-u", "www-data", PS, "php", "/tmp/seed-demo.php"], capture_output=True, text=True)
    assert r.returncode == 0 and "Client OAuth" in r.stdout, r.stdout + r.stderr
