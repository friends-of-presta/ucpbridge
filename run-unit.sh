#!/bin/sh
# Tests unitaires PHPUnit du module ucpbridge, exécutés dans le conteneur PrestaShop du banc.
# Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
set -e
cd "$(dirname "$0")"
C="${UCP_PROJECT:-bench-ucp-bridge}-prestashop-1"  # UCP_PROJECT : projet docker compose du banc
docker exec "$C" rm -rf /tmp/ucpbridge-src
docker cp ucpbridge "$C":/tmp/ucpbridge-src >/dev/null
docker cp tools/phpunit.phar "$C":/tmp/phpunit.phar >/dev/null
docker exec -w /tmp/ucpbridge-src/tests "$C" php /tmp/phpunit.phar "$@"
