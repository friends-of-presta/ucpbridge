"""Client MCP minimal (JSON-RPC 2.0 sur HTTP streamable) pour le service UCP de la boutique du banc.

  python3 mcp_client.py                      scénario « t-shirt colibri taille M » jusqu'au continue_url
  python3 mcp_client.py --url http://localhost:8092/ucp/v1/mcp --token ucpk_...

Bibliothèque standard uniquement. Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import argparse
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
import uuid

PROFIL_AGENT = "https://agent.contrib.test/.well-known/ucp"
# Outils qui modifient l'état : meta["idempotency-key"] obligatoire (une clé neuve par appel si rien n'est fourni).
MUTATIONS = {"create_cart", "update_cart", "cancel_cart", "create_checkout", "update_checkout", "complete_checkout", "cancel_checkout"}


class McpErreur(Exception):
    def __init__(self, http, erreur):
        super().__init__(f"HTTP {http} : {erreur}")
        self.http = http
        self.erreur = erreur


class ClientMcp:
    def __init__(self, url, jeton=None):
        self.url = url
        self.jeton = jeton
        self.n = 0
        self.session = None

    def _post(self, message):
        entetes = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream",
                   "MCP-Protocol-Version": "2025-06-18"}
        if self.jeton:
            entetes["Authorization"] = f"Bearer {self.jeton}"
        if self.session:
            entetes["Mcp-Session-Id"] = self.session
        req = urllib.request.Request(self.url, data=json.dumps(message).encode(), method="POST", headers=entetes)
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                code, texte, hdr = r.status, r.read().decode(), r.headers
        except urllib.error.HTTPError as e:
            code, texte, hdr = e.code, e.read().decode(), e.headers
        self.session = hdr.get("Mcp-Session-Id") or self.session
        return code, (json.loads(texte) if texte.strip() else None)

    def requete(self, methode, params=None):
        self.n += 1
        msg = {"jsonrpc": "2.0", "id": self.n, "method": methode}
        if params is not None:
            msg["params"] = params
        code, doc = self._post(msg)
        if not isinstance(doc, dict) or "result" not in doc:
            raise McpErreur(code, (doc or {}).get("error", doc) if isinstance(doc, dict) else doc)
        return doc["result"]

    def notifier(self, methode):
        return self._post({"jsonrpc": "2.0", "method": methode})[0]

    def initialiser(self):
        res = self.requete("initialize", {"protocolVersion": "2025-06-18", "capabilities": {},
                                          "clientInfo": {"name": "client-mcp-contrib", "version": "0.1.0"}})
        self.notifier("notifications/initialized")
        return res

    def outils(self):
        return self.requete("tools/list")["tools"]

    def appeler(self, nom, arguments, idem=None):
        meta = {"ucp-agent": {"profile": PROFIL_AGENT}}
        if idem is None and nom in MUTATIONS:
            idem = str(uuid.uuid4())
        if idem:
            meta["idempotency-key"] = idem
        res = self.requete("tools/call", {"name": nom, "arguments": {"meta": meta} | arguments})
        return res["structuredContent"]


def variante(produit, **options):
    for v in produit["variants"]:
        o = {x["name"]: x["label"] for x in v["options"]}
        if all(o.get(k) == val for k, val in options.items()):
            return v
    return None


def scenario(client, sortie=print):
    info = client.initialiser()
    sortie(f"initialize : serveur {info['serverInfo']['name']} {info['serverInfo']['version']}, protocole {info['protocolVersion']}")
    noms = [t["name"] for t in client.outils()]
    sortie(f"tools/list : {len(noms)} outils : {', '.join(noms)}")
    res = client.appeler("search_catalog", {"catalog": {"query": "t-shirt colibri"}})
    p = res["products"][0]
    sortie(f"search_catalog « t-shirt colibri » : {p['id']} {p['title']}, {len(p['variants'])} variantes")
    detail = client.appeler("get_product", {"catalog": {"id": p["id"]}})["product"]
    v = variante(detail, Taille="M", Couleur="Blanc")
    sortie(f"get_product {p['id']} : variante taille M retenue : {v['id']} {v['sku']} ({v['title']}), "
           f"{v['price']['amount'] / 100:.2f} € TTC")
    panier = client.appeler("create_cart", {"cart": {"line_items": [{"item": {"id": p["id"], "variant_id": v["id"]}, "quantity": 1}]}},
                            idem=str(uuid.uuid4()))
    li = panier["line_items"][0]
    sortie(f"create_cart : {panier['id']}, {li['quantity']} x {li['item']['title']}, total "
           f"{[t for t in panier['totals'] if t['type'] == 'total'][0]['amount'] / 100:.2f} €")
    sortie(f"continue_url : {panier['continue_url']}")
    return panier


def main():
    a = argparse.ArgumentParser()
    a.add_argument("--url", default="http://localhost:8092/ucp/v1/mcp")
    a.add_argument("--token", default=None, help="clé API de la plateforme par défaut (lue en base du banc si absente)")
    a = a.parse_args()
    if not a.url.startswith(("http://localhost", "http://127.0.0.1", "https://")):
        sys.exit("URL locale ou HTTPS attendue")
    jeton = a.token or json.loads(subprocess.run(["docker", "exec", os.environ.get("UCP_PROJECT", "bench-ucp-bridge") + "-mysql-1", "mysql", "-uroot", "-pbench", "-N", "-B", "prestashop",
                                                  "-e", "SELECT value FROM ps_configuration WHERE name='UCPBRIDGE_API_KEYS'"],
                                                 capture_output=True, text=True).stdout.strip().splitlines()[-1])["default"]["key"]
    scenario(ClientMcp(a.url, jeton))


if __name__ == "__main__":
    main()
