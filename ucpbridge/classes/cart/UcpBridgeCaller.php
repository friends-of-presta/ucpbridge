<?php
/**
 * Identité de l'appelant d'une requête UCP, partagée par les classes du panier et de la caisse : propriétaire des
 * ressources (plateforme ou client OAuth), en-tête UCP-Agent et client PrestaShop authentifié.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCaller
{
    /** @var string appelant propriétaire : « platform:{id} » (clé API) ou « user:{client_id}:{id_customer} » (jeton OAuth) */
    public $owner = '';

    /** @var string en-tête UCP-Agent de l'appelant (consigné dans le journal de preuve) */
    public $agent = '';

    /** @var int client PrestaShop authentifié par jeton OAuth (0 = invité) */
    public $idUser = 0;

    /**
     * Cloisonnement strict : un panier, une session ou une commande n'est lisible et modifiable que par l'appelant
     * qui l'a créé (même plateforme, ou même client OAuth et même client final). Aucune ressource « ouverte à tous ».
     */
    public function owns(array $s)
    {
        return '' !== $this->owner && hash_equals((string) ($s['owner'] ?? ''), $this->owner);
    }
}
