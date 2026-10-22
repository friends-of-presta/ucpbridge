#!/bin/sh
# Tests unitaires PHPUnit du module ucpbridge, exécutés dans le conteneur PrestaShop du banc.
# PHPUnit 12 exige PHP 8.3 : sur une image plus ancienne (PrestaShop 8, PHP 8.1), les tests tournent avec le PHP de
# l'hôte (UNIT_PHP, défaut php), et le message l'indique. Ce sont des tests de logique pure, sans PrestaShop.
# Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
set -e
cd "$(dirname "$0")"
C="${UCP_PROJECT:-bench-ucp-bridge}-prestashop-1"  # UCP_PROJECT : projet docker compose du banc
PHP83='exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'
if docker exec "$C" php -r "$PHP83" 2>/dev/null; then
  docker exec "$C" rm -rf /tmp/ucpbridge-src
  docker cp ucpbridge "$C":/tmp/ucpbridge-src >/dev/null
  docker cp tools/phpunit.phar "$C":/tmp/phpunit.phar >/dev/null
  docker exec -w /tmp/ucpbridge-src/tests "$C" php /tmp/phpunit.phar "$@"
  exit
fi
VC=$(docker exec "$C" php -r 'echo PHP_VERSION;')
HOTE="${UNIT_PHP:-php}"
if ! "$HOTE" -r "$PHP83" 2>/dev/null; then
  echo "PHPUnit 12 exige PHP 8.3 : conteneur $C en PHP $VC et aucun PHP 8.3 sur l'hôte (variable UNIT_PHP)" >&2
  exit 1
fi
echo "Conteneur $C en PHP $VC, PHPUnit 12 exige 8.3 : tests unitaires lancés avec le PHP de l'hôte ($("$HOTE" -r 'echo PHP_VERSION;'))"
cd ucpbridge/tests
"$HOTE" ../../tools/phpunit.phar "$@"
