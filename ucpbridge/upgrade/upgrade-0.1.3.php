<?php
/**
 * Mise à jour 0.1.2 vers 0.1.3, lancée par PrestaShop (gestionnaire de modules, `prestashop:module upgrade`) quand la
 * version des fichiers dépasse celle enregistrée en base. Sans désinstallation : aucune ligne ni valeur configurée
 * n'est modifiée, seules les tables, colonnes, hooks et clés de configuration manquants sont créés. Rejouable.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Ucpbridge $module
 *
 * @return bool false : PrestaShop désactive le module et garde l'ancienne version en base (rien n'est perdu)
 */
function upgrade_module_0_1_3($module)
{
    return $module->upgradeInPlace();
}
