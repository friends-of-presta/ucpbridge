"""Scénarios de la deuxième relecture indépendante (0.1.1-test) : I-A, I-B, I-C, I-D (et M9), panier UCP figé.

Les sondes PHP chargent en mémoire un module dérivé dont validateOrder lève une exception (hook tiers défaillant) ou
arrête brutalement le processus : rien n'est écrit dans le conteneur.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import json
import subprocess
import threading
import time

from conftest import BASE, CLIENT_ID, M_BLANC, NOYAU_PHP, PS, appel, secret_client_test, sql, sql_val
from test_04_relecture import complete, id_cart, id_client_demo, ouvrir_lien


def php_noyau(code):
    """PHP dans le conteneur, PrestaShop et son noyau Symfony chargés (validateOrder en a besoin)."""
    script = ("<?php define('_PS_ADMIN_DIR_', '/var/www/html/admin'); require '/var/www/html/config/config.inc.php';\n"
              + NOYAU_PHP + code)
    r = subprocess.run(["docker", "exec", "-i", "-u", "www-data", PS, "php"], input=script, capture_output=True, text=True)
    return r.stdout.strip(), r.returncode


# Module dérivé : APRES = validateOrder crée la commande puis échoue ; ARRET = le processus meurt (après la commande
# si COMMANDE, sinon juste après le débit).
HARNAIS = r"""
$m = Module::getInstanceByName('ucpbridge');
class SondeUcp extends Ucpbridge {
    public function validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method = 'Unknown', $message = null,
        $extra_vars = [], $currency_special = null, $dont_touch_amount = false, $secure_key = false, ?Shop $shop = null, ?string $order_reference = null) {
        if (COMMANDE) {
            parent::validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method, $message, $extra_vars, $currency_special, $dont_touch_amount, $secure_key, $shop, $order_reference);
        }
        if (ARRET) { exit(255); }
        throw new Exception('exception d\'un hook après création de la commande (simulée)');
    }
}
$ctx = Context::getContext();
$ctx->link = new Link(); $ctx->shop = new Shop(1); $ctx->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT')); $ctx->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
$svc = new UcpBridgeCart($ctx, new SondeUcp());
$svc->setCaller('platform');
$s = $svc->load(SID, 'checkout');
list($code, $doc) = $svc->completeCheckout($s, ['payment' => ['instruments' => [INSTR]]]);
echo "\nRESULT " . json_encode(['code' => $code, 'status' => $doc['status'] ?? null]);
"""


def sonde(boutique, sid, commande, arret):
    code = (HARNAIS.replace("SID", json.dumps(sid)).replace("INSTR", "json_decode(" + json.dumps(json.dumps(boutique.instrument_mock())) + ", true)")
            .replace("COMMANDE", "true" if commande else "false").replace("ARRET", "true" if arret else "false"))
    return php_noyau(code)


def journal(sid):
    return [tuple(l.split("\t")) for l in sql(f"SELECT operation, status, amount FROM ps_ucpbridge_payment WHERE session_id='{sid}' "
                                               "ORDER BY id_payment").splitlines()]


def debits_reussis(sid):
    return [j for j in journal(sid) if j[0] == "charge" and j[1] == "succeeded"]


def statut(sid):
    return sql_val(f"SELECT status FROM ps_ucpbridge_session WHERE id_session='{sid}'")


def commandes(cart):
    return sql(f"SELECT id_order, ROUND(total_paid_tax_incl*100) FROM ps_orders WHERE id_cart={cart}").splitlines()


def simultane(fonctions):
    res = [None] * len(fonctions)
    depart = threading.Barrier(len(fonctions))

    def run(i, f):
        depart.wait()
        res[i] = f()

    fils = [threading.Thread(target=run, args=(i, f)) for i, f in enumerate(fonctions)]
    for f in fils:
        f.start()
    for f in fils:
        f.join()
    return res


# ------------------------------------ I-A : exception après création de la commande ------------------------------------

def test_ia_exception_apres_commande_ni_remboursement_ni_echec(boutique):
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    out, _ = sonde(boutique, sid, commande=True, arret=False)
    assert "RESULT" in out, out[-800:]
    assert len(commandes(cart)) == 1
    assert statut(sid) == "completed"
    assert [j[0] for j in journal(sid)] == ["charge"], journal(sid)  # aucun remboursement
    g = boutique.get(f"/checkout-sessions/{sid}").doc
    assert g["status"] == "completed" and g["order"]["id"].startswith("ord_")
    assert boutique.get(f"/orders/{g['order']['id']}").code == 200
    logs = sql(f"SELECT severity, message FROM ps_log WHERE message LIKE '%{sid}%'")
    assert "commande conservée" in logs and "sans commande" not in logs, logs


# ------------------------------------ I-C : update concurrent de complete ------------------------------------

def test_ic_update_pendant_complete_10_tours(boutique):
    bilan = []
    for _ in range(10):
        s = boutique.session_prete()
        sid, cart = s["id"], id_cart(s["id"])
        m = s["fulfillment"]["methods"][0]
        r_put, r_cpl = simultane([
            lambda: boutique.put(f"/checkout-sessions/{sid}", {"line_items": [{"item": M_BLANC, "quantity": 3}], "fulfillment": {"methods": [m]}}),
            lambda: complete(boutique, sid),
        ])
        fin = statut(sid)
        bilan.append((r_put.code, r_cpl.code, fin))
        # Jamais de débit nul ni de session perdue.
        assert all(int(j[2]) > 0 for j in debits_reussis(sid)), journal(sid)
        assert not [j for j in journal(sid) if j[0] == "refund"], journal(sid)
        assert fin in ("completed", "ready_for_complete"), bilan
        assert r_put.code in (200, 409), r_put.texte[:300]
        qte = int(sql_val(f"SELECT IFNULL(SUM(quantity),0) FROM ps_cart_product WHERE id_cart={cart}"))
        if r_put.code == 200:
            # Mise à jour enregistrée : ses totaux sont ceux du panier, et la commande éventuelle les reprend.
            assert qte == 3
            assert boutique.get(f"/checkout-sessions/{sid}").doc["totals"] == r_put.doc["totals"]
        else:
            assert any(x["code"] == "not_modifiable" for x in r_put.doc["messages"])
            assert qte == 1
        if fin == "completed":
            cmd = commandes(cart)
            assert len(cmd) == 1 and len(debits_reussis(sid)) == 1
            assert cmd[0].split("\t")[1] == debits_reussis(sid)[0][2]
        else:
            assert not debits_reussis(sid) and not commandes(cart)
    print(bilan)


def test_ic_aucun_debit_sur_panier_vide(boutique):
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    sql(f"DELETE FROM ps_cart_product WHERE id_cart={cart}")
    r = complete(boutique, sid)
    assert r.code == 200, r.texte[:300]
    assert r.doc["status"] != "completed"
    assert not debits_reussis(sid), journal(sid)
    assert statut(sid) not in ("failed", "handed_off", "canceled")


# ------------------------------------ I-D et M9 : rotation et révocation ------------------------------------

def jetons(client_id=CLIENT_ID):
    out = subprocess.run(["docker", "exec", "-i", "-u", "www-data", PS, "php"], capture_output=True, text=True, input=(
        "<?php define('_PS_ADMIN_DIR_', '/var/www/html/admin'); require '/var/www/html/config/config.inc.php';\n"
        f"Module::getInstanceByName('ucpbridge'); echo json_encode(UcpBridgeOAuth::issueTokens({json.dumps(client_id)}, {id_client_demo()}, "
        "'dev.ucp.shopping.checkout:manage dev.ucp.shopping.order:read')[1]);")).stdout
    return json.loads(out.strip().splitlines()[-1])


def revoquer_tout():
    return subprocess.run(["docker", "exec", "-i", "-u", "www-data", PS, "php"], capture_output=True, text=True, input=(
        "<?php define('_PS_ADMIN_DIR_', '/var/www/html/admin'); require '/var/www/html/config/config.inc.php';\n"
        f"Module::getInstanceByName('ucpbridge'); UcpBridgeOAuth::revokeAllFor({json.dumps(CLIENT_ID)}, {id_client_demo()});")).returncode


def rafraichir(meta, rt):
    return appel("POST", meta["token_endpoint"], form={"grant_type": "refresh_token", "refresh_token": rt},
                 basic=(CLIENT_ID, secret_client_test()))


def test_m9_cinq_rafraichissements_simultanes_une_seule_paire(boutique, oauth_meta):
    d = jetons()
    res = simultane([lambda: rafraichir(oauth_meta, d["refresh_token"]) for _ in range(5)])
    gagnants = [r for r in res if r.code == 200]
    assert len(gagnants) == 1, [r.code for r in res]
    assert all(r.code == 400 and r.doc["error"] == "invalid_grant" for r in res if r.code != 200)
    assert boutique.get("/orders/ord_" + "0" * 24, jeton=gagnants[0].doc["access_token"]).code == 404  # paire valide
    revoquer_tout()


def test_id_revocation_concurrente_40_tours_aucun_survivant(boutique, oauth_meta):
    survivants = []
    for i in range(40):
        d = jetons()
        delai = (i % 20) * 0.008

        def ref():
            time.sleep(delai)
            return rafraichir(oauth_meta, d["refresh_token"])
        _, r = simultane([revoquer_tout, ref])
        if r.code == 200:
            for jeton in (r.doc["access_token"],):
                c = boutique.get("/orders/ord_" + "0" * 24, jeton=jeton).code
                if c != 401:
                    survivants.append((i, c))
            # Le jeton de rafraîchissement émis pendant la révocation ne doit pas servir non plus.
            if rafraichir(oauth_meta, r.doc["refresh_token"]).code == 200:
                survivants.append((i, "refresh"))
    revoquer_tout()
    assert not survivants, survivants
    # Une nouvelle liaison après la révocation fonctionne (la date de révocation ne bloque que le passé).
    time.sleep(0.01)
    d = jetons()
    assert boutique.get("/orders/ord_" + "0" * 24, jeton=d["access_token"]).code == 404
    revoquer_tout()


# ------------------------------------ I-B : processus tué après le débit ------------------------------------

def vieillir(sid, minutes=30):
    sql(f"UPDATE ps_ucpbridge_session SET date_upd = DATE_SUB(NOW(), INTERVAL {minutes} MINUTE) WHERE id_session='{sid}'")


def rapprocher(minutes=10):
    r = subprocess.run(["docker", "exec", "-u", "www-data", PS, "php", "/var/www/html/modules/ucpbridge/bin/reconcile.php",
                        f"--minutes={minutes}"], capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr


def test_ib_debit_puis_arret_brutal_rembourse_par_le_rapprochement(boutique):
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    out, rc = sonde(boutique, sid, commande=False, arret=True)
    assert rc == 255 and "RESULT" not in out
    assert statut(sid) == "complete_in_progress" and len(debits_reussis(sid)) == 1 and not commandes(cart)
    # Session récente : le rapprochement ne la touche pas (une finalisation peut être en cours).
    rapprocher(10)
    assert statut(sid) == "complete_in_progress"
    vieillir(sid)
    rc, out = rapprocher(10)
    assert rc == 0, out
    assert sid in out
    assert statut(sid) == "failed"
    assert [(j[0], j[1]) for j in journal(sid)] == [("charge", "succeeded"), ("refund", "succeeded")], journal(sid)
    g = boutique.get(f"/checkout-sessions/{sid}").doc
    assert g["status"] == "canceled" and {"order_failed", "payment_refunded"} <= {m["code"] for m in g["messages"]}
    assert "rapprochement" in sql_val(f"SELECT message FROM ps_log WHERE message LIKE '%{sid}%' ORDER BY id_log DESC LIMIT 1")


def test_ib_commande_creee_puis_arret_brutal_finalisee(boutique):
    s = boutique.session_prete()
    sid, cart = s["id"], id_cart(s["id"])
    _, rc = sonde(boutique, sid, commande=True, arret=True)
    assert rc == 255 and statut(sid) == "complete_in_progress" and len(commandes(cart)) == 1
    vieillir(sid)
    rc, out = rapprocher(10)
    assert rc == 0, out
    assert statut(sid) == "completed"
    assert [j[0] for j in journal(sid)] == ["charge"]
    g = boutique.get(f"/checkout-sessions/{sid}").doc
    assert g["status"] == "completed" and boutique.get(f"/orders/{g['order']['id']}").code == 200


def test_ib_reservation_sans_debit_rendue_et_bouton_back_office(boutique):
    s = boutique.session_prete()
    sid = s["id"]
    sql(f"UPDATE ps_ucpbridge_session SET status='complete_in_progress' WHERE id_session='{sid}'")
    vieillir(sid)
    html = subprocess.run(["docker", "exec", "-i", "-u", "www-data", PS, "php"], capture_output=True, text=True, input=(
        "<?php define('_PS_ADMIN_DIR_', '/var/www/html/admin'); require '/var/www/html/config/config.inc.php';\n"
        "Context::getContext()->employee = new Employee(1); $m = Module::getInstanceByName('ucpbridge'); $h = $m->getContent();\n"
        "preg_match('/name=\"ucp_csrf\" value=\"([0-9a-f]+)\"/', $h, $t);\n"
        "$_POST = ['submitUcpbridgeReconcile' => '1', 'ucp_csrf' => $t[1]]; echo $m->getContent();")).stdout
    assert "Rapprocher les sessions bloquées" in html
    assert statut(sid) == "ready_for_complete"
    assert complete(boutique, sid).doc["status"] == "completed"


# ------------------------------------ Panier UCP figé après ouverture du lien ------------------------------------

def test_panier_ucp_fige_apres_ouverture_du_lien(boutique):
    p = boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 1}]}).doc
    cart = int(sql_val(f"SELECT id_cart FROM ps_ucpbridge_session WHERE id_session='{p['id']}'"))
    ouvrir_lien(p["continue_url"])
    r = boutique.put(f"/carts/{p['id']}", {"line_items": [{"item": M_BLANC, "quantity": 5}]})
    assert r.code == 409, r.texte[:300]
    assert any(m["code"] == "checkout_handed_off" for m in r.doc["messages"])
    assert int(sql_val(f"SELECT SUM(quantity) FROM ps_cart_product WHERE id_cart={cart}")) == 1
    assert boutique.post("/checkout-sessions", {"cart_id": p["id"]}).code == 404
    assert BASE  # banc local


def test_ib_script_de_rapprochement_inaccessible_par_le_web():
    r = appel("GET", f"{BASE}/modules/ucpbridge/bin/reconcile.php")
    assert r.code in (403, 404), (r.code, r.texte[:200])
    assert "session(s)" not in r.texte
