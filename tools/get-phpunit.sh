#!/bin/sh
# Télécharge tools/phpunit.phar (non versionné) à la version épinglée et vérifie sa somme SHA-256.
# Sans effet si le fichier présent a déjà la bonne somme. Utilisé par l'intégration continue et en local.
# Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
set -eu
cd "$(dirname "$0")"
VERSION=12.5.40
SHA256=83f8098d7e6513befa61cd0df2689d42398d43404969b0dc21528ff52674ebc6
somme() { if command -v sha256sum >/dev/null; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
if [ -f phpunit.phar ] && [ "$(somme phpunit.phar)" = "$SHA256" ]; then
  echo "phpunit.phar $VERSION déjà présent"; exit 0
fi
curl -fsSL --retry 3 -o phpunit.phar.tmp "https://phar.phpunit.de/phpunit-$VERSION.phar"
if [ "$(somme phpunit.phar.tmp)" != "$SHA256" ]; then
  rm -f phpunit.phar.tmp; echo "somme SHA-256 inattendue pour phpunit-$VERSION.phar" >&2; exit 1
fi
mv phpunit.phar.tmp phpunit.phar
echo "phpunit.phar $VERSION téléchargé et vérifié"
