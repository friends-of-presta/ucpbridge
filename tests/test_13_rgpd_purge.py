"""Issue #13 : durées de conservation configurables et purge (ligne de commande bin/purge.php et back-office), avec
compte rendu. Les commandes passées ne sont jamais touchées ; les clients invités sans commande créés par le module
sont purgés, ceux qui ont commandé ne le sont jamais.

Les données « anciennes » sont vieillies en base (dates reculées) plutôt qu'attendues.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import json
import re
import subprocess
import uuid

import pytest

from conftest import DEST_FR, M_BLANC, PS, config, id_commande, php, set_config, sql, sql_val
from test_04_relecture import id_cart

DUREES = {"UCPBRIDGE_RETENTION_SESSIONS": "30", "UCPBRIDGE_RETENTION_GUESTS": "30", "UCPBRIDGE_RETENTION_OAUTH": "30",
          "UCPBRIDGE_RETENTION_PAYMENTS": "3650", "UCPBRIDGE_RETENTION_PROOFS": "3650"}
FAUSSE_COMMANDE = 990000000  # id_order sans commande réelle, pour les lignes de preuve fabriquées


def purge(*args):
    r = subprocess.run(["docker", "exec", "-u", "www-data", PS, "php", "modules/ucpbridge/bin/purge.php", *args],
                       capture_output=True, text=True)
    assert r.returncode == 0, r.stdout + r.stderr
    return r.stdout


def compte(rapport, libelle):
    m = re.search(re.escape(libelle) + r" : (\d+)", rapport)
    assert m, f"« {libelle} » absent du compte rendu :\n{rapport}"
    return int(m.group(1))


def commandes_intactes():
    """Empreinte des commandes de la boutique : la purge ne doit jamais y toucher."""
    return sql("SELECT COUNT(*), IFNULL(MAX(id_order), 0), IFNULL(SUM(total_paid_tax_incl), 0), "
               "(SELECT COUNT(*) FROM ps_order_detail), (SELECT COUNT(*) FROM ps_address WHERE id_address IN "
               "(SELECT id_address_delivery FROM ps_orders)) FROM ps_orders")


def acheteur():
    return {"email": f"rgpd-{uuid.uuid4().hex[:10]}@contrib.test", "first_name": "Dominique", "last_name": "Durand",
            "phone_number": "+33600000000"}


def client_de(sid):
    return int(sql_val(f"SELECT id_customer FROM ps_cart WHERE id_cart = {id_cart(sid)}"))


def vieillir(sid=None, client=None, jours=40):
    if sid:
        sql(f"UPDATE ps_ucpbridge_session SET date_upd = NOW() - INTERVAL {jours} DAY, expires_at = NOW() - INTERVAL {jours} DAY "
            f"WHERE id_session = '{sid}'")
    if client:
        sql(f"UPDATE ps_ucpbridge_customer SET date_add = NOW() - INTERVAL {jours} DAY WHERE id_customer = {client}")
        sql(f"UPDATE ps_customer SET date_add = NOW() - INTERVAL {jours} DAY WHERE id_customer = {client}")


@pytest.fixture(autouse=True)
def durees_par_defaut():
    yield
    for k, v in DUREES.items():
        set_config(k, v)


def test_durees_par_defaut():
    assert {k: config(k) for k in DUREES} == DUREES


def test_client_invite_sans_commande_purge(boutique):
    s = boutique.session_prete(buyer=acheteur())  # e-mail fourni : client invité créé, adresse, panier, aucune commande
    sid, cart, client = s["id"], id_cart(s["id"]), client_de(s["id"])
    adresse = int(sql_val(f"SELECT id_address_delivery FROM ps_cart WHERE id_cart = {cart}"))
    assert sql_val(f"SELECT is_guest FROM ps_customer WHERE id_customer = {client}") == "1"
    assert sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_customer WHERE id_customer = {client}") == "1"  # créé par le module
    avant = commandes_intactes()
    # Récent : rien ne bouge.
    purge()
    assert sql_val(f"SELECT COUNT(*) FROM ps_customer WHERE id_customer = {client}") == "1"
    vieillir(sid, client)
    rapport = purge()
    assert compte(rapport, "Clients invités sans commande supprimés") >= 1
    assert compte(rapport, "Sessions et paniers UCP supprimés") >= 1
    assert sql_val(f"SELECT COUNT(*) FROM ps_customer WHERE id_customer = {client}") == "0"
    assert sql_val(f"SELECT COUNT(*) FROM ps_address WHERE id_address = {adresse}") == "0"
    assert sql_val(f"SELECT COUNT(*) FROM ps_cart WHERE id_cart = {cart}") == "0"
    assert sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_session WHERE id_session = '{sid}'") == "0"
    assert sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_customer WHERE id_customer = {client}") == "0"
    assert commandes_intactes() == avant
    # Compte rendu aussi au journal PrestaShop.
    assert "ucpbridge purge RGPD" in sql_val("SELECT message FROM ps_log WHERE message LIKE 'ucpbridge purge RGPD%' ORDER BY id_log DESC LIMIT 1")


def test_client_invite_qui_a_commande_jamais_purge(boutique):
    b = acheteur()
    s = boutique.session_prete(buyer=b)
    r = boutique.payer_mock(s["id"])
    assert r.doc["status"] == "completed", r.texte[:500]
    sid, client, ref = s["id"], client_de(s["id"]), r.doc["order"]["id"]
    oid = id_commande(ref)
    vieillir(sid, client, jours=400)
    avant = commandes_intactes()
    rapport = purge()
    assert compte(rapport, "Sessions terminées par une commande, données personnelles retirées") >= 1
    # Le client et sa commande restent, avec l'adresse de livraison.
    assert sql_val(f"SELECT CONCAT(email, ' ', is_guest) FROM ps_customer WHERE id_customer = {client}") == b["email"] + " 1"
    assert sql_val(f"SELECT COUNT(*) FROM ps_orders WHERE id_order = {oid} AND id_customer = {client}") == "1"
    assert commandes_intactes() == avant
    # La session garde la commande, plus les données personnelles de l'acheteur.
    payload = json.loads(sql_val(f"SELECT payload FROM ps_ucpbridge_session WHERE id_session = '{sid}'"))
    assert not {"buyer", "destination", "consent", "terms_acceptance"} & set(payload) and payload["minimized_at"]
    assert b["email"] not in sql_val(f"SELECT payload FROM ps_ucpbridge_session WHERE id_session = '{sid}'")
    o = boutique.get(f"/orders/{ref}")
    assert o.code == 200 and o.doc["id"] == ref, o.texte[:300]
    c = boutique.get(f"/checkout-sessions/{sid}")
    assert c.code == 200 and c.doc["status"] == "completed" and "buyer" not in c.doc
    # Rejouer la purge ne change plus rien pour cette session.
    payload2 = sql_val(f"SELECT payload FROM ps_ucpbridge_session WHERE id_session = '{sid}'")
    purge()
    assert sql_val(f"SELECT payload FROM ps_ucpbridge_session WHERE id_session = '{sid}'") == payload2


def test_session_en_cours_jamais_purgee(boutique):
    s = boutique.session_prete(buyer=acheteur())
    sid, client = s["id"], client_de(s["id"])
    sql(f"UPDATE ps_ucpbridge_session SET status = 'complete_in_progress' WHERE id_session = '{sid}'")
    vieillir(sid, client)
    try:
        purge()
        assert sql_val(f"SELECT status FROM ps_ucpbridge_session WHERE id_session = '{sid}'") == "complete_in_progress"
        assert sql_val(f"SELECT COUNT(*) FROM ps_customer WHERE id_customer = {client}") == "1"
    finally:
        sql(f"UPDATE ps_ucpbridge_session SET status = 'canceled' WHERE id_session = '{sid}'")


@pytest.fixture
def lignes_fabriquees():
    """Codes, jetons, paiements, preuves et rétractations datés, insérés directement (jeu isolé, nettoyé ensuite)."""
    t = uuid.uuid4().hex[:8]
    vieux, recent = f"{FAUSSE_COMMANDE}", f"{FAUSSE_COMMANDE + 1}"
    sql("INSERT INTO ps_ucpbridge_oauth_code (code_hash, client_id, id_customer, redirect_uri, code_challenge, scope, used, issued_us, expires_at) VALUES "
        f"(SHA2('{t}-code-vieux', 256), 'purge-test', 2, 'x', 'x', 'x', 1, 0, NOW() - INTERVAL 40 DAY), "
        f"(SHA2('{t}-code-recent', 256), 'purge-test', 2, 'x', 'x', 'x', 0, 0, NOW() + INTERVAL 5 MINUTE)")
    sql("INSERT INTO ps_ucpbridge_oauth_token (token_hash, kind, client_id, id_customer, scope, revoked, issued_us, expires_at, date_add) VALUES "
        f"(SHA2('{t}-expire', 256), 'access', 'purge-test', 2, 'x', 0, 0, NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 41 DAY), "
        f"(SHA2('{t}-revoque-vieux', 256), 'refresh', 'purge-test', 2, 'x', 1, 0, NOW() + INTERVAL 5 DAY, NOW() - INTERVAL 40 DAY), "
        f"(SHA2('{t}-revoque-recent', 256), 'refresh', 'purge-test', 2, 'x', 1, 0, NOW() + INTERVAL 20 DAY, NOW()), "
        f"(SHA2('{t}-valide', 256), 'refresh', 'purge-test', 2, 'x', 0, 0, NOW() + INTERVAL 20 DAY, NOW())")
    sql("INSERT INTO ps_ucpbridge_payment (session_id, provider, operation, status, transaction_id, amount, currency, message, date_add) VALUES "
        f"('purge-{t}', 'mock', 'charge', 'succeeded', 'vieux', 100, 'EUR', '', NOW() - INTERVAL 11 YEAR), "
        f"('purge-{t}', 'mock', 'charge', 'succeeded', 'recent', 100, 'EUR', '', NOW() - INTERVAL 9 YEAR)")
    for oid, age in ((vieux, 11), (recent, 9)):
        sql("INSERT INTO ps_ucpbridge_proof (id_order, id_cart, session_id, links, disclosures, totals, consent, date_add) VALUES "
            f"({oid}, 0, 'purge-{t}', '[]', '[]', '[]', '{{}}', NOW() - INTERVAL {age} YEAR)")
        sql(f"INSERT INTO ps_ucpbridge_withdrawal (id_order, email, ip, email_sent, date_add) VALUES ({oid}, 'x@contrib.test', '', 1, NOW() - INTERVAL {age} YEAR)")
    yield t
    sql("DELETE FROM ps_ucpbridge_oauth_code WHERE client_id = 'purge-test'")
    sql("DELETE FROM ps_ucpbridge_oauth_token WHERE client_id = 'purge-test'")
    sql(f"DELETE FROM ps_ucpbridge_payment WHERE session_id = 'purge-{t}'")
    sql(f"DELETE FROM ps_ucpbridge_proof WHERE id_order IN ({vieux}, {recent})")
    sql(f"DELETE FROM ps_ucpbridge_withdrawal WHERE id_order IN ({vieux}, {recent})")


def restants(t):
    return {
        "codes": sql_val("SELECT COUNT(*) FROM ps_ucpbridge_oauth_code WHERE client_id = 'purge-test'"),
        "jetons": sql(f"SELECT token_hash = SHA2('{t}-revoque-recent', 256) OR token_hash = SHA2('{t}-valide', 256) "
                      "FROM ps_ucpbridge_oauth_token WHERE client_id = 'purge-test' ORDER BY 1").split(),
        "paiements": sql(f"SELECT transaction_id FROM ps_ucpbridge_payment WHERE session_id = 'purge-{t}'").split(),
        "preuves": sql(f"SELECT id_order FROM ps_ucpbridge_proof WHERE session_id = 'purge-{t}'").split(),
        "retractations": sql(f"SELECT id_order FROM ps_ucpbridge_withdrawal WHERE id_order >= {FAUSSE_COMMANDE}").split(),
    }


def test_purge_oauth_paiements_preuves(lignes_fabriquees):
    t = lignes_fabriquees
    avant = commandes_intactes()
    simulation = purge("--dry-run")
    assert "simulation" in simulation.lower() and compte(simulation, "Jetons OAuth à supprimer") >= 2
    assert restants(t)["codes"] == "2"  # la simulation ne supprime rien
    rapport = purge()
    assert compte(rapport, "Codes OAuth supprimés") >= 1 and compte(rapport, "Jetons OAuth supprimés") >= 2
    assert compte(rapport, "Preuves de commande supprimées") >= 1 and compte(rapport, "Demandes de rétractation supprimées") >= 1
    assert restants(t) == {"codes": "1", "jetons": ["1", "1"], "paiements": ["recent"], "preuves": [str(FAUSSE_COMMANDE + 1)],
                           "retractations": [str(FAUSSE_COMMANDE + 1)]}
    assert commandes_intactes() == avant


def test_duree_zero_ne_purge_jamais(lignes_fabriquees):
    t = lignes_fabriquees
    set_config("UCPBRIDGE_RETENTION_PROOFS", 0)
    set_config("UCPBRIDGE_RETENTION_PAYMENTS", 0)
    purge()
    r = restants(t)
    assert sorted(r["preuves"]) == [str(FAUSSE_COMMANDE), str(FAUSSE_COMMANDE + 1)] and sorted(r["paiements"]) == ["recent", "vieux"]


def back_office(post=None):
    code = "Context::getContext()->employee = new Employee(1); $m = Module::getInstanceByName('ucpbridge');\n"
    if post is not None:
        code += ("$h = $m->getContent(); preg_match('/name=\"ucp_csrf\" value=\"([0-9a-f]+)\"/', $h, $t);\n"
                 f"$_POST = json_decode({json.dumps(json.dumps(post))}, true);\n"
                 "if (($_POST['ucp_csrf'] ?? '') === 'JETON') { $_POST['ucp_csrf'] = $t[1]; }\n")
    return php(code + "echo $m->getContent();")


def test_durees_et_purge_depuis_le_back_office(boutique, lignes_fabriquees):
    html = back_office()
    for k, v in DUREES.items():
        assert re.search(rf'name="{k}"[^>]*value="{v}"', html), k
    assert "bin/purge.php" in html
    nouvelles = dict(DUREES, UCPBRIDGE_RETENTION_SESSIONS="45")
    # Jeton faux : rien n'est enregistré ni purgé.
    html = back_office({"submitUcpbridgeRetention": "1", "ucp_csrf": "faux", **nouvelles})
    assert "Jeton de sécurité invalide" in html and config("UCPBRIDGE_RETENTION_SESSIONS") == "30"
    html = back_office({"submitUcpbridgeRetention": "1", "ucp_csrf": "JETON", **nouvelles})
    assert config("UCPBRIDGE_RETENTION_SESSIONS") == "45"
    # Durée invalide : refusée, valeur précédente conservée, autres réglages du module intacts.
    secret = config("UCPBRIDGE_MOCK_SECRET")
    html = back_office({"submitUcpbridgeRetention": "1", "ucp_csrf": "JETON", **dict(nouvelles, UCPBRIDGE_RETENTION_GUESTS="-3")})
    assert "Durée de conservation invalide" in html and config("UCPBRIDGE_RETENTION_GUESTS") == "30"
    assert config("UCPBRIDGE_MOCK_SECRET") == secret
    # Simulation puis purge, avec compte rendu.
    html = back_office({"submitUcpbridgePurgeDry": "1", "ucp_csrf": "JETON"})
    assert "Simulation" in html and "Jetons OAuth à supprimer" in html
    assert restants(lignes_fabriquees)["codes"] == "2"
    html = back_office({"submitUcpbridgePurge": "1", "ucp_csrf": "faux"})
    assert "Jeton de sécurité invalide" in html and restants(lignes_fabriquees)["codes"] == "2"
    html = back_office({"submitUcpbridgePurge": "1", "ucp_csrf": "JETON"})
    assert "Compte rendu de la purge" in html and "Jetons OAuth supprimés" in html
    assert restants(lignes_fabriquees)["codes"] == "1"


def test_session_abandonnee_sans_email_adresse_orpheline_purgee(boutique):
    """Adresse saisie sans e-mail : rattachée à aucun client, elle part avec la session."""
    r = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}], "currency": "EUR"})
    sid = r.doc["id"]
    boutique.put(f"/checkout-sessions/{sid}", {"fulfillment": {"methods": [
        {"type": "shipping", "destinations": [DEST_FR], "selected_destination_id": DEST_FR["id"]}]}})
    adresse = int(sql_val(f"SELECT id_address_delivery FROM ps_cart WHERE id_cart = {id_cart(sid)}"))
    assert adresse and sql_val(f"SELECT id_customer FROM ps_address WHERE id_address = {adresse}") == "0"
    vieillir(sid)
    rapport = purge()
    assert compte(rapport, "Adresses sans client supprimées") >= 1
    assert sql_val(f"SELECT COUNT(*) FROM ps_address WHERE id_address = {adresse}") == "0"


# ---------------------------------------------------------------------------------------------------------------------
# Relecture 0.1.3-beta : B1 (session « failed » et acheteur à rembourser), I1 (planchers légaux), I2 (sessions déjà
# minimisées, lots), I3 (compte rendu des invités).

def cli(*args):
    r = subprocess.run(["docker", "exec", "-u", "www-data", PS, "php", *args], capture_output=True, text=True)
    assert r.returncode == 0, r.stdout + r.stderr
    return r.stdout


def session_en_echec(boutique, mode):
    """Invité créé par le module, session passée en « failed » par le vrai rapprochement, aucune commande, rien de
    remboursé. mode « adaptateur_absent » : débit réussi au journal, remboursement impossible (adaptateur introuvable) ;
    « debit_incertain » : débit engagé sans résultat connu (charge_unknown)."""
    b = acheteur()
    s = boutique.session_prete(buyer=b)
    sid = s["id"]
    client = client_de(sid)
    sql(f"UPDATE ps_ucpbridge_session SET status = 'complete_in_progress', date_upd = NOW() - INTERVAL 1 HOUR WHERE id_session = '{sid}'")
    if mode == "adaptateur_absent":
        sql("INSERT INTO ps_ucpbridge_payment (session_id, provider, operation, status, transaction_id, amount, currency, message, date_add) "
            f"VALUES ('{sid}', 'adaptateur_disparu', 'charge', 'succeeded', 'ch_rgpd_{sid[-8:]}', 2990, 'EUR', '', NOW() - INTERVAL 1 HOUR)")
    else:
        sql("UPDATE ps_ucpbridge_session SET payload = JSON_SET(payload, '$.charge_started_at', '2026-01-01T00:00:00Z', "
            f"'$.charge_provider', 'stripe', '$.charge_unknown', true) WHERE id_session = '{sid}'")
    out = cli("modules/ucpbridge/bin/reconcile.php", "--minutes=15")
    assert sid in out, out
    assert sql_val(f"SELECT status FROM ps_ucpbridge_session WHERE id_session = '{sid}'") == "failed"
    f = json.loads(sql_val(f"SELECT payload FROM ps_ucpbridge_session WHERE id_session = '{sid}'"))["failure"]
    assert f["refunded"] is False
    return sid, client, b


def vieillir_paiements(sid, jours):
    sql(f"UPDATE ps_ucpbridge_payment SET date_add = NOW() - INTERVAL {jours} DAY WHERE session_id = '{sid}'")


def restant_echec(sid, client, cart, b):
    return {
        "session": sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_session WHERE id_session = '{sid}' AND status = 'failed'"),
        "client": sql_val(f"SELECT COUNT(*) FROM ps_customer WHERE id_customer = {client}"),
        "email_client": sql_val(f"SELECT COUNT(*) FROM ps_customer WHERE email = '{b['email']}'"),
        "panier": sql_val(f"SELECT COUNT(*) FROM ps_cart WHERE id_cart = {cart}"),
        "invite_du_module": sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_customer WHERE id_customer = {client}"),
    }


@pytest.mark.parametrize("mode", ["adaptateur_absent", "debit_incertain"])
def test_session_failed_et_acheteur_a_rembourser_conserves(boutique, mode):
    """B1 : une session « failed » (débit non remboursé ou incertain) et son acheteur invité suivent la durée du journal
    des paiements, pas celle des sessions ni des invités : le marchand doit encore savoir qui rembourser."""
    sid, client, b = session_en_echec(boutique, mode)
    cart = id_cart(sid)
    vieillir(sid, client, jours=40)
    vieillir_paiements(sid, 40)
    intact = {"session": "1", "client": "1", "email_client": "1", "panier": "1", "invite_du_module": "1"}
    purge()
    assert restant_echec(sid, client, cart, b) == intact, f"session failed ou acheteur supprimé au bout de 40 jours ({mode})"
    # Durée du journal des paiements « jamais » : conservés même après 11 ans.
    set_config("UCPBRIDGE_RETENTION_PAYMENTS", 0)
    vieillir(sid, client, jours=4020)
    vieillir_paiements(sid, 4020)
    purge()
    assert restant_echec(sid, client, cart, b) == intact
    # Durée du journal des paiements écoulée : la session part avec le journal, puis l'invité sans commande.
    set_config("UCPBRIDGE_RETENTION_PAYMENTS", 3650)
    purge()
    assert restant_echec(sid, client, cart, b) == {"session": "0", "client": "0", "email_client": "0", "panier": "0",
                                                     "invite_du_module": "0"}
    assert sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_payment WHERE session_id = '{sid}'") == "0"


def test_compte_rendu_compte_panier_et_adresse_de_l_invite(boutique):
    """I3 : les paniers et adresses supprimés avec un invité figurent au compte rendu, identique en simulation."""
    purge()  # rien d'ancien en attente : les nombres ci-dessous ne concernent que cet invité
    s = boutique.session_prete(buyer=acheteur())
    sid, cart, client = s["id"], id_cart(s["id"]), client_de(s["id"])
    adresse = int(sql_val(f"SELECT id_address_delivery FROM ps_cart WHERE id_cart = {cart}"))
    vieillir(sid, client)
    simulation = purge("--dry-run")
    rapport = purge()
    lignes = {"sessions": "Sessions et paniers UCP", "paniers": "Paniers PrestaShop sans commande",
              "adresses": "Adresses sans client", "invites": "Clients invités sans commande"}
    reel = {k: compte(rapport, v + (" supprimées" if k == "adresses" else " supprimés")) for k, v in lignes.items()}
    simule = {k: compte(simulation, v + " à supprimer") for k, v in lignes.items()}
    assert reel == {"sessions": 1, "paniers": 1, "adresses": 1, "invites": 1}, rapport
    assert simule == reel, simulation
    assert sql_val(f"SELECT COUNT(*) FROM ps_cart WHERE id_cart = {cart}") == "0"
    assert sql_val(f"SELECT COUNT(*) FROM ps_address WHERE id_address = {adresse} AND deleted = 0") == "0"


def selection_sessions(lot=500):
    """Identifiants des sessions que purgeSessions() relit (premier lot), pour la durée des sessions configurée."""
    return php("Module::getInstanceByName('ucpbridge'); $c = UcpBridgeRetention::cutoff(UcpBridgePurge::durations()['sessions'], time());\n"
               f"foreach ((new UcpBridgePurge(true))->sessionBatch($c, false, '', {int(lot)}) as $r) {{ echo $r['id_session'], \"\\n\"; }}").split()


def test_sessions_deja_minimisees_plus_relues(boutique):
    """I2 : une session déjà minimisée n'est plus relue par la purge (filtre SQL), la purge avance par lots."""
    s = boutique.session_prete(buyer=acheteur())
    r = boutique.payer_mock(s["id"])
    assert r.doc["status"] == "completed", r.texte[:500]
    sid = s["id"]
    vieillir(sid, jours=40)
    assert sid in selection_sessions()
    purge()
    assert json.loads(sql_val(f"SELECT payload FROM ps_ucpbridge_session WHERE id_session = '{sid}'")).get("minimized_at")
    assert sid not in selection_sessions(), "session déjà minimisée encore relue par purgeSessions()"


def test_purge_par_lots(boutique):
    """I2 : plus de sessions que la taille d'un lot, toutes traitées, en simulation comme pour de bon."""
    purge()
    sids = []
    for _ in range(5):
        r = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}], "currency": "EUR"})
        sids.append(r.doc["id"])
        vieillir(r.doc["id"])
    liste = ",".join(f"'{s}'" for s in sids)
    code = ("Module::getInstanceByName('ucpbridge'); $r = (new UcpBridgePurge(%s, null, 2))->run(); echo $r['sessions_deleted'];")
    assert php(code % "true") == "5"
    assert sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_session WHERE id_session IN ({liste})") == "5"
    assert php(code % "false") == "5"
    assert sql_val(f"SELECT COUNT(*) FROM ps_ucpbridge_session WHERE id_session IN ({liste})") == "0"


def purge_refusee(*args):
    r = subprocess.run(["docker", "exec", "-u", "www-data", PS, "php", "modules/ucpbridge/bin/purge.php", *args],
                       capture_output=True, text=True)
    assert r.returncode != 0, r.stdout
    return r.stdout + r.stderr


@pytest.mark.parametrize("cle", ["UCPBRIDGE_RETENTION_PAYMENTS", "UCPBRIDGE_RETENTION_PROOFS"])
def test_durees_legales_planchers_back_office(boutique, cle):
    """I1 : journal des paiements, preuves et rétractations : 3650 jours au moins (durée légale), ou 0 = jamais."""
    for v in ("1", "30", "365", "3649"):
        html = back_office({"submitUcpbridgeRetention": "1", "ucp_csrf": "JETON", **dict(DUREES, **{cle: v})})
        assert "alert-danger" in html and "3650" in html and config(cle) == "3650", (cle, v)
        assert "trop courte" in html
    for v in ("0", "3650", "4000"):
        back_office({"submitUcpbridgeRetention": "1", "ucp_csrf": "JETON", **dict(DUREES, **{cle: v})})
        assert config(cle) == v, (cle, v)


@pytest.mark.parametrize("cle", ["UCPBRIDGE_RETENTION_PAYMENTS", "UCPBRIDGE_RETENTION_PROOFS"])
def test_purge_refusee_sous_le_plancher_legal(boutique, lignes_fabriquees, cle):
    """I1 : durée légale trop courte en configuration (écrite hors du back-office) : la purge refuse de tourner, en
    ligne de commande comme depuis le back-office, et ne supprime rien."""
    t = lignes_fabriquees
    avant = restants(t)
    set_config(cle, 365)
    sortie = purge_refusee()
    assert "3650" in sortie and "trop courte" in sortie, sortie
    assert purge_refusee("--dry-run")
    assert restants(t) == avant
    html = back_office({"submitUcpbridgePurge": "1", "ucp_csrf": "JETON"})
    assert "alert-danger" in html and "3650" in html and 'id="ucpbridge-purge-report"' not in html
    assert restants(t) == avant
