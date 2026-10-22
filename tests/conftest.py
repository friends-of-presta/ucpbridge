"""Socle des tests d'intégration ucpbridge contre la boutique du banc (http://localhost:8092).

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import base64
import hashlib
import http.server
import json
import os
import re
import secrets
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

import pytest

BASE = os.environ.get("UCP_BASE", "http://localhost:8092")
ICI = os.path.dirname(os.path.abspath(__file__))
BANC = os.path.dirname(ICI)
PROJET = os.environ.get("UCP_PROJECT", "bench-ucp-bridge")  # nom du projet docker compose du banc
MYSQL = ["docker", "exec", f"{PROJET}-mysql-1", "mysql", "-uroot", "-pbench",
         "--default-character-set=utf8mb4", "prestashop", "-N", "-B", "-e"]
PS = f"{PROJET}-prestashop-1"
AGENT = 'profile="https://agent.contrib.test/.well-known/ucp"; version="2026-04-08"'
CLIENT_ID = "google-agent-test"
REDIRECT = "http://localhost:8765/callback"
DEMO_EMAIL = "pub@prestashop.com"
DEMO_MDP = "123456789"

assert BASE.startswith(("http://localhost", "http://127.0.0.1")), "banc local uniquement"

DEST_FR = {"id": "dest_1", "first_name": "Agent", "last_name": "Contrib", "street_address": "1 rue de la Paix",
           "address_locality": "Paris", "postal_code": "75002", "address_country": "FR"}
DEST_BE = {"id": "dest_be", "first_name": "Agent", "last_name": "Contrib", "street_address": "1 rue Neuve",
           "address_locality": "Bruxelles", "postal_code": "1000", "address_country": "BE"}
BUYER = {"email": "agent@contrib.test", "first_name": "Agent", "last_name": "Contrib"}
M_BLANC = {"id": "demo_1-M-BLANC"}


def sql(requete):
    """Résultat brut (sans en-têtes), une ligne par enregistrement, colonnes séparées par tabulation."""
    r = subprocess.run(MYSQL + [requete], capture_output=True, text=True)
    return r.stdout.strip()


def sql_val(requete):
    out = sql(requete)
    return out.splitlines()[-1] if out else ""


def config(nom):
    return sql_val(f"SELECT value FROM ps_configuration WHERE name='{nom}' ORDER BY id_configuration DESC LIMIT 1")


def cle_api(plateforme="default"):
    """Clé API d'une plateforme (UCPBRIDGE_API_KEYS : une clé par plateforme)."""
    return json.loads(config("UCPBRIDGE_API_KEYS") or "{}").get(plateforme, {}).get("key", "")


def php(code):
    """Exécute du PHP dans le conteneur de la boutique, PrestaShop chargé."""
    script = "<?php define('_PS_ADMIN_DIR_', '/var/www/html/admin'); require '/var/www/html/config/config.inc.php';\n" + code
    r = subprocess.run(["docker", "exec", "-i", "-u", "www-data", PS, "php"], input=script, capture_output=True, text=True)
    return r.stdout.strip()


class Reponse:
    def __init__(self, code, doc, entetes, texte):
        self.code = code
        self.doc = doc
        self.entetes = entetes
        self.texte = texte


def appel(methode, url, corps=None, jeton=None, idem=None, form=None, basic=None, entetes=None, auto_idem=True):
    h = {"Accept": "application/json", "UCP-Agent": AGENT, "Request-Id": str(uuid.uuid4())}
    if jeton:
        h["Authorization"] = f"Bearer {jeton}"
    if basic:
        h["Authorization"] = "Basic " + base64.b64encode(f"{basic[0]}:{basic[1]}".encode()).decode()
    donnees = None
    if form is not None:
        h["Content-Type"] = "application/x-www-form-urlencoded"
        donnees = urllib.parse.urlencode(form).encode()
    elif corps is not None:
        h["Content-Type"] = "application/json"
        donnees = json.dumps(corps).encode()
    if methode != "GET" and form is None and auto_idem:
        h["Idempotency-Key"] = idem or str(uuid.uuid4())
    h.update(entetes or {})
    req = urllib.request.Request(url, data=donnees, method=methode, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            code, texte, hdr = r.status, r.read().decode(), dict(r.headers)
    except urllib.error.HTTPError as e:
        code, texte, hdr = e.code, e.read().decode(), dict(e.headers)
    try:
        doc = json.loads(texte)
    except ValueError:
        doc = None
    return Reponse(code, doc, {k.lower(): v for k, v in hdr.items()}, texte)


def id_commande(ref):
    """id_order PrestaShop d'une commande UCP à partir de son identifiant exposé (opaque)."""
    ref = str(ref)
    if ref.isdigit():
        return int(ref)
    return int(sql_val(f"SELECT id_order FROM ps_ucpbridge_session WHERE order_ref='{re.sub(r'[^A-Za-z0-9_]', '', ref)}'"))


def total(doc, typ="total"):
    return sum(t["amount"] for t in doc["totals"] if t["type"] == typ)


def somme_coherente(doc):
    return sum(t["amount"] for t in doc["totals"] if t["type"] != "total") == total(doc)


class Boutique:
    def __init__(self):
        self.base = BASE
        self.cle = cle_api()
        self.secret_mock = config("UCPBRIDGE_MOCK_SECRET")
        r = appel("GET", f"{BASE}/.well-known/ucp")
        assert r.code == 200, r.texte[:300]
        self.profil = r.doc["ucp"]
        self.api = next(s["endpoint"] for s in self.profil["services"]["dev.ucp.shopping"] if s["transport"] == "rest")

    def handler_id(self, nom):
        return self.profil["payment_handlers"][nom][0]["id"]

    def post(self, chemin, corps, jeton=None, **kw):
        return appel("POST", self.api + chemin, corps, jeton or self.cle, **kw)

    def put(self, chemin, corps, jeton=None, **kw):
        return appel("PUT", self.api + chemin, corps, jeton or self.cle, **kw)

    def get(self, chemin, jeton=None, **kw):
        return appel("GET", self.api + chemin, None, jeton or self.cle, **kw)

    def instrument_mock(self):
        return {"id": "instr_1", "handler_id": self.handler_id("fr.410gone.ucpbridge.mock"), "type": "mock",
                "selected": True, "credential": {"type": "token", "token": self.secret_mock}}

    def session_prete(self, lignes=None, buyer=BUYER, dest=DEST_FR, codes=None, jeton=None, transporteur="My carrier", extra=None):
        corps = {"line_items": lignes or [{"item": M_BLANC, "quantity": 1}], "currency": "EUR"}
        if buyer:
            corps["buyer"] = buyer
        if codes is not None:
            corps["discounts"] = {"codes": codes}
        corps.update(extra or {})
        r = self.post("/checkout-sessions", corps, jeton)
        assert r.code == 201, r.texte[:500]
        sid = r.doc["id"]
        s = r.doc
        if dest:
            r = self.put(f"/checkout-sessions/{sid}", {"fulfillment": {"methods": [
                {"type": "shipping", "destinations": [dest], "selected_destination_id": dest["id"]}]}}, jeton)
            s = r.doc
        methode = s["fulfillment"]["methods"][0]
        options = methode["groups"][0]["options"]
        choix = next((o for o in options if o["title"] == transporteur), options[0])
        methode["groups"][0]["selected_option_id"] = choix["id"]
        r = self.put(f"/checkout-sessions/{sid}", {"fulfillment": {"methods": [methode]}}, jeton)
        assert r.code == 200
        return r.doc

    def payer_mock(self, sid, jeton=None, idem=None):
        return self.post(f"/checkout-sessions/{sid}/complete", {"payment": {"instruments": [self.instrument_mock()]}}, jeton, idem=idem)


@pytest.fixture(scope="session")
def boutique():
    return Boutique()


# ------------------------------- OAuth (identity linking) -------------------------------

def pkce():
    verifier = base64.urlsafe_b64encode(secrets.token_bytes(48)).rstrip(b"=").decode()
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
    return verifier, challenge


def secret_client_test():
    m = re.search(r"DEMO_OAUTH_SECRET', '([^']+)'", open(os.path.join(BANC, "seed-demo.php"), encoding="utf-8").read())
    return m.group(1)


class _Retour(http.server.BaseHTTPRequestHandler):
    recu = {}

    def do_GET(self):
        _Retour.recu = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(self.path).query))
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.end_headers()
        self.wfile.write(b"ok")

    def log_message(self, *a):
        pass


def flux_autorisation(meta, capture=None):
    """Joue l'acheteur dans Chromium : connexion PrestaShop, consentement, retour. Renvoie (code, verifier)."""
    from playwright.sync_api import sync_playwright
    verifier, challenge = pkce()
    state = secrets.token_urlsafe(16)
    url = meta["authorization_endpoint"] + "?" + urllib.parse.urlencode({
        "response_type": "code", "client_id": CLIENT_ID, "redirect_uri": REDIRECT, "state": state,
        "scope": "dev.ucp.shopping.checkout:manage dev.ucp.shopping.order:read",
        "code_challenge": challenge, "code_challenge_method": "S256"})
    _Retour.recu = {}
    serveur = http.server.HTTPServer(("127.0.0.1", 8765), _Retour)
    threading.Thread(target=serveur.serve_forever, daemon=True).start()
    try:
        with sync_playwright() as pw:
            nav = pw.chromium.launch()
            page = nav.new_page(viewport={"width": 1100, "height": 760}, locale="fr-FR")
            page.goto(url)
            page.fill("#login-form input[name=email]", DEMO_EMAIL)
            page.fill("#login-form input[name=password]", DEMO_MDP)
            page.click("#login-form button[type=submit]")
            page.wait_for_selector("button.allow", timeout=15000)
            if capture:
                page.screenshot(path=capture, full_page=True)
            page.click("button.allow")
            page.wait_for_url(REDIRECT + "*", timeout=15000)
            nav.close()
    finally:
        serveur.shutdown()
        serveur.server_close()
    assert _Retour.recu.get("state") == state and "code" in _Retour.recu
    return _Retour.recu["code"], verifier


@pytest.fixture(scope="session")
def oauth_meta():
    r = appel("GET", f"{BASE}/.well-known/oauth-authorization-server")
    assert r.code == 200
    return r.doc


def jeton_client(meta):
    code, verifier = flux_autorisation(meta)
    r = appel("POST", meta["token_endpoint"], form={"grant_type": "authorization_code", "code": code,
                                                    "redirect_uri": REDIRECT, "code_verifier": verifier},
              basic=(CLIENT_ID, secret_client_test()))
    assert r.code == 200, r.texte
    return r.doc


def jeton_gpay(tok="tok_visa"):
    return json.dumps({"id": tok, "object": "token", "type": "card", "livemode": False, "created": int(time.time()),
                       "card": {"object": "card", "brand": "Visa", "last4": "4242", "exp_month": 12, "exp_year": 2030}})


# ------------------------------- Back-office, e-mails -------------------------------

MAILPIT = os.environ.get("MAILPIT", "http://localhost:8025")
ADMIN_EMAIL = "demo@contrib.test"     # identifiants de démo du banc (docker-compose.yml)
ADMIN_MDP = "ContribBench2026"


def set_config(nom, valeur):
    php(f"Configuration::updateValue({json.dumps(nom)}, {json.dumps(str(valeur))});")


def admin_dir():
    r = subprocess.run(["docker", "exec", PS, "sh", "-c", "ls -d /var/www/html/admin* | grep -v admin-api"], capture_output=True, text=True)
    return os.path.basename(r.stdout.strip())


def admin_login(page):
    page.goto(f"{BASE}/{admin_dir()}/index.php?controller=AdminLogin")
    page.fill("#email", ADMIN_EMAIL)
    page.fill("#passwd", ADMIN_MDP)
    page.click("#submit_login")
    page.wait_for_load_state("networkidle")


def mails(destinataire):
    r = appel("GET", f"{MAILPIT}/api/v1/search?" + urllib.parse.urlencode({"query": f'to:"{destinataire}"'}))
    return (r.doc or {}).get("messages", [])


def mail(id_):
    return appel("GET", f"{MAILPIT}/api/v1/message/{id_}").doc


def attendre_mails(destinataire, n=1, delai=20):
    fin = time.time() + delai
    while time.time() < fin:
        m = mails(destinataire)
        if len(m) >= n:
            return m
        time.sleep(0.5)
    return mails(destinataire)
