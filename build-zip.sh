#!/bin/sh
# Construit l'archive installable du module (sans tests ni fichiers du banc) dans dist/.
# Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
set -e
unset ZIP ZIPOPT  # variables lues par Info-ZIP comme options par défaut
cd "$(dirname "$0")"
V=$(sed -n "s/.*\$this->version = '\([0-9.]*\)'.*/\1/p" ucpbridge/ucpbridge.php)
OUT="dist/ucpbridge-$V-beta.zip"
TMP=$(mktemp -d)
rsync -a --exclude tests --exclude '.DS_Store' --exclude '__pycache__' --exclude '*.orig' ucpbridge "$TMP/"
if grep -rl "$(printf '\342\200\224')" "$TMP/ucpbridge" >/dev/null; then echo "tiret cadratin trouvé" >&2; exit 1; fi
mkdir -p dist && rm -f "$OUT"
ABS="$(pwd)/$OUT"
(cd "$TMP" && zip -qrX "$ABS" ucpbridge)
rm -rf "$TMP"
echo "$OUT"
unzip -l "$OUT" | tail -1
