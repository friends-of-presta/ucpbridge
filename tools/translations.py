#!/usr/bin/env python3
"""Met à jour les catalogues XLIFF du module (ucpbridge/translations/<locale>/ModulesUcpbridge<Domaine>.<locale>.xlf).

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.

Les textes sources (anglais) sont relevés dans le code : UcpBridgeI18n::admin|shop|api('...') en PHP,
{l s='...' d='Modules.Ucpbridge.*'} en Smarty. Les traductions existantes sont conservées, les chaînes disparues du
code sont retirées, les nouvelles sont ajoutées (anglais : texte source ; français : cible vide, à traduire, que
TranslationsTest signale). Usage :

    python3 tools/translations.py            # met à jour les catalogues
    python3 tools/translations.py --check    # code de sortie 1 si un catalogue n'est pas à jour
"""
import hashlib
import os
import re
import sys
import xml.etree.ElementTree as ET

ICI = os.path.dirname(os.path.abspath(__file__))
MODULE = os.path.join(os.path.dirname(ICI), "ucpbridge")
DOMAINES = {"admin": "ModulesUcpbridgeAdmin", "shop": "ModulesUcpbridgeShop", "api": "ModulesUcpbridgeApi"}
LOCALES = ["fr-FR", "en-US"]
NS = "urn:oasis:names:tc:xliff:document:1.2"
RE_PHP = re.compile(r"UcpBridgeI18n::(admin|shop|api)\(\s*'((?:[^'\\]|\\.)*)'")
RE_TPL = re.compile(r"\{l s='((?:[^'\\]|\\.)*)'[^}]*?d='Modules\.Ucpbridge\.(Admin|Shop|Api)'")


def sources():
    trouve = {d: [] for d in DOMAINES.values()}
    for racine, dossiers, fichiers in os.walk(MODULE):
        dossiers[:] = sorted(d for d in dossiers if d != "tests")
        for nom in sorted(fichiers):
            chemin = os.path.join(racine, nom)
            if nom.endswith(".php"):
                for dom, txt in RE_PHP.findall(open(chemin, encoding="utf-8").read()):
                    s = txt.replace("\\\\", "\x00").replace("\\'", "'").replace("\x00", "\\")
                    trouve[DOMAINES[dom]].append(s)
            elif nom.endswith(".tpl"):
                for txt, dom in RE_TPL.findall(open(chemin, encoding="utf-8").read()):
                    trouve["ModulesUcpbridge" + dom].append(txt.replace("\\'", "'"))
    return {d: list(dict.fromkeys(l)) for d, l in trouve.items()}


def fichier(domaine, locale):
    return os.path.join(MODULE, "translations", locale, f"{domaine}.{locale}.xlf")


def lire(domaine, locale):
    f = fichier(domaine, locale)
    if not os.path.exists(f):
        return {}
    racine = ET.parse(f).getroot()
    return {u.find(f"{{{NS}}}source").text or "": u.find(f"{{{NS}}}target").text or ""
            for u in racine.iter(f"{{{NS}}}trans-unit")}


def ecrire(domaine, locale, chaines, cibles):
    def x(s):
        return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
    lignes = ['<?xml version="1.0" encoding="UTF-8"?>',
              "<!-- Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. "
              "Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. -->",
              f'<xliff xmlns="{NS}" version="1.2">',
              f'  <file original="ucpbridge" source-language="en-US" target-language="{locale}" datatype="plaintext">',
              "    <body>"]
    for s in sorted(chaines):
        ident = hashlib.md5(s.encode("utf-8")).hexdigest()
        lignes += [f'      <trans-unit id="{ident}">', f"        <source>{x(s)}</source>",
                   f"        <target>{x(cibles.get(s, ''))}</target>", "      </trans-unit>"]
    lignes += ["    </body>", "  </file>", "</xliff>", ""]
    contenu = "\n".join(lignes)
    os.makedirs(os.path.dirname(fichier(domaine, locale)), exist_ok=True)
    avant = open(fichier(domaine, locale), encoding="utf-8").read() if os.path.exists(fichier(domaine, locale)) else None
    if avant != contenu:
        open(fichier(domaine, locale), "w", encoding="utf-8").write(contenu)
        return True
    return False


def main(verifier=False, graine=None):
    change = False
    for domaine, chaines in sources().items():
        for locale in LOCALES:
            cibles = lire(domaine, locale)
            if locale == "en-US":
                cibles = {s: cibles.get(s) or s for s in chaines}
            elif graine:
                cibles = {s: cibles.get(s) or graine.get((domaine, s), "") for s in chaines}
            if verifier:
                change |= sorted(cibles) != sorted(chaines) or set(lire(domaine, locale)) != set(chaines)
            else:
                change |= ecrire(domaine, locale, chaines, cibles)
    if verifier and change:
        print("catalogues de traduction à mettre à jour : python3 tools/translations.py")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(verifier="--check" in sys.argv))
