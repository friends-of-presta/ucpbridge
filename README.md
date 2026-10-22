# UCP Bridge pour PrestaShop

[![Tests](https://github.com/friends-of-presta/ucpbridge/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/friends-of-presta/ucpbridge/actions/workflows/tests.yml)

Module PrestaShop qui fait parler une boutique le protocole UCP (Universal Commerce Protocol, https://ucp.dev), celui
qu'utilisent Google Universal Cart et les agents d'achat : profil `/.well-known/ucp`, catalogue et déclinaisons,
panier transférable, caisse native, codes promo, comptes clients (OAuth), CGV, rétractation et serveur MCP.

> **Version bêta 0.1.2, prototype : ne pas utiliser en production.** Non validé par Google, Universal Cart n'est pas
> encore ouvert en Europe, paiement réel non éprouvé. PrestaShop 9.1 uniquement. Relectures et contributions bienvenues.

Écrit par Christophe Vidal pour l'épisode 15 du podcast **Contrib.** (Thomas Rayrat et Christophe Vidal), donné à
l'association **Friends of Presta**.

## Contenu du dépôt

- `ucpbridge/` : le module (à zipper pour l'installer, voir `build-zip.sh`), sa documentation complète
  (`ucpbridge/README.md`) et son journal des modifications.
- `tests/`, `run-tests.sh`, `docker-compose.yml`, `seed-demo.php` : banc d'essai PrestaShop 9.1 en docker et
  211 tests (95 unitaires PHPUnit, 116 d'intégration pytest et Playwright).
- `mcp_client.py` : client MCP minimal pour essayer la boutique comme un agent.

## Démarrage rapide

```sh
./tools/get-phpunit.sh   # PHPUnit épinglé, non versionné
docker compose -p bench-ucp-bridge up -d
./run-tests.sh
./build-zip.sh      # archive installable dans dist/
```

## Intégration continue

Le workflow GitHub Actions [`.github/workflows/tests.yml`](.github/workflows/tests.yml) tourne sur chaque pull request
et à chaque push sur `main` (lancement manuel possible depuis l'onglet Actions). Aucun secret n'est nécessaire.

- **Lint PHP et unitaires** (PHP 8.3 et 8.5) : `php -l` sur tout le module, tests PHPUnit sans base, puis
  `build-zip.sh` (refuse un tiret cadratin) ; l'archive installable est gardée en artefact.
- **Intégration** : le banc `docker-compose.yml` démarre sur le runner, puis `run-tests.sh` déroule installation
  fraîche, lint, unitaires et la suite pytest et Playwright. En cas d'échec, l'artefact `journaux-integration`
  contient `test-logs/` (installation, unitaires, intégration, rapport JUnit), les journaux docker, ceux de
  PrestaShop et les captures Playwright.
- Mis en cache : images docker du banc, paquets pip, Chromium de Playwright, PHPUnit et cache npm.

PHPUnit n'est pas versionné : `tools/get-phpunit.sh` télécharge la version épinglée et vérifie sa somme SHA-256.

Les scripts acceptent des variables pour faire tourner un second banc à côté du premier (valeurs par défaut entre
parenthèses) : `UCP_PROJECT` (`bench-ucp-bridge`), `UCP_PORT` (`8092`), `MAILPIT_PORT` (`8025`),
`STRIPE_MOCK_PORT` (`12111`), `UCP_WAIT` (`900` secondes d'attente au premier démarrage).

```sh
UCP_PROJECT=ucpbridge-2 UCP_PORT=8093 MAILPIT_PORT=8026 STRIPE_MOCK_PORT=12112 ./run-tests.sh
```

Première contribution depuis un fork : GitHub met le run en attente jusqu'à ce qu'un mainteneur l'approuve
(bouton « Approve and run » de la pull request ou de l'onglet Actions).

## Licence

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib.
Licence AFL-3.0 (Academic Free License 3.0) : voir `LICENSE`.

Faille de sécurité : ne pas ouvrir d'issue publique, voir `SECURITY.md`.
