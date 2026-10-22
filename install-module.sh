#!/bin/sh
# Copie et (ré)installe le module ucpbridge dans la boutique du banc (8092 par défaut, projet UCP_PROJECT), puis charge le jeu de démo.
#   ./install-module.sh [--reinstall | --zip]   (--zip : depuis dist/ucpbridge-<version>-beta.zip)
# Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
set -e
cd "$(dirname "$0")"
C="${UCP_PROJECT:-bench-ucp-bridge}-prestashop-1"  # UCP_PROJECT : projet docker compose du banc
if [ "$1" = "--reinstall" ] || [ "$1" = "--zip" ]; then
  docker exec -u www-data "$C" php bin/console prestashop:module uninstall ucpbridge >/dev/null 2>&1 || true
fi
docker exec "$C" rm -rf /var/www/html/modules/ucpbridge
if [ "$1" = "--zip" ]; then
  # Installation depuis l'archive publiée (dist/), comme un marchand.
  Z=$(./build-zip.sh | sed -n 1p)
  docker cp "$Z" "$C":/tmp/ucpbridge.zip
  docker exec "$C" sh -c 'cd /var/www/html/modules && unzip -q -o /tmp/ucpbridge.zip'
else
  docker cp ucpbridge "$C":/var/www/html/modules/ucpbridge
fi
# Les tests unitaires restent hors de la boutique (ils tournent depuis /tmp, voir run-unit.sh).
docker exec "$C" rm -rf /var/www/html/modules/ucpbridge/tests
docker cp seed-demo.php "$C":/tmp/seed-demo.php
docker exec "$C" chown -R www-data:www-data /var/www/html/modules/ucpbridge
docker exec -u www-data "$C" sh -c 'php bin/console prestashop:module install ucpbridge 2>&1 | tail -3'
docker exec "$C" sh -c 'rm -rf /var/www/html/var/cache/*'
docker exec -u www-data "$C" php /tmp/seed-demo.php
