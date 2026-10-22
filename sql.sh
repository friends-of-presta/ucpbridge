#!/bin/sh
# Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
# Requête SQL rapide sur la base de la boutique 8092 (banc ucpbridge)
exec docker exec "${UCP_PROJECT:-bench-ucp-bridge}-mysql-1" mysql -uroot -pbench --default-character-set=utf8mb4 prestashop -e "$1" 2>/dev/null
