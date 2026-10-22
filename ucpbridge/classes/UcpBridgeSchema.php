<?php
/**
 * Évolution du schéma en place (mises à jour sans désinstallation) : colonnes ajoutées aux tables existantes et clés
 * de configuration manquantes, sans jamais toucher aux lignes ni aux valeurs déjà présentes. Tout est rejouable :
 * une colonne déjà là n'est pas recréée, une clé déjà configurée garde sa valeur.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeSchema
{
    /* ------------------------------ Logique pure (testée sans PrestaShop) ------------------------------ */

    /** Nom de colonne ou de table accepté dans un ALTER TABLE (lettres, chiffres, souligné). */
    public static function isIdentifier($name)
    {
        return is_string($name) && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $name);
    }

    /**
     * Colonnes déclarées absentes de la table, dans l'ordre de déclaration (comparaison insensible à la casse,
     * comme MySQL).
     *
     * @param array $declared [colonne => définition SQL]
     * @param array $existing noms des colonnes présentes
     */
    public static function missingColumns(array $declared, array $existing)
    {
        $have = array_map('strtolower', $existing);

        return array_filter($declared, function ($col) use ($have) {
            return !in_array(strtolower((string) $col), $have, true);
        }, ARRAY_FILTER_USE_KEY);
    }

    /** Instruction d'ajout d'une colonne ; la définition vient du code du module, jamais d'une saisie. */
    public static function addColumnSql($table, $column, $definition)
    {
        if (!self::isIdentifier($table) || !self::isIdentifier($column)) {
            throw new InvalidArgumentException('Identifiant SQL invalide : ' . $table . '.' . $column);
        }

        return 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition;
    }

    /** Clés de configuration à créer : celles des valeurs par défaut qui n'existent pas encore. */
    public static function missingConfig(array $defaults, array $existingKeys)
    {
        return array_diff_key($defaults, array_flip($existingKeys));
    }

    /* ------------------------------ Base de la boutique ------------------------------ */

    public static function columns($table)
    {
        $rows = Db::getInstance()->executeS('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = \'' . pSQL($table) . '\'') ?: [];

        return array_column($rows, 'COLUMN_NAME');
    }

    /**
     * Ajoute les colonnes manquantes. $added : [table sans préfixe => [colonne => définition SQL]], colonnes créées
     * après la première publication (0.1.2) et donc absentes d'une table plus ancienne.
     */
    public static function addMissingColumns(array $added)
    {
        $ok = true;
        foreach ($added as $table => $columns) {
            $full = _DB_PREFIX_ . $table;
            foreach (self::missingColumns($columns, self::columns($full)) as $col => $def) {
                $ok = Db::getInstance()->execute(self::addColumnSql($full, $col, $def)) && $ok;
            }
        }

        return $ok;
    }

    /**
     * Crée les clés de configuration manquantes avec leur valeur par défaut (une fonction anonyme est appelée pour
     * calculer la valeur, par exemple un secret aléatoire), sans modifier les autres.
     */
    public static function addMissingConfig(array $defaults)
    {
        // Lecture en base (toutes portées) plutôt que Configuration::hasKey() : le cache mémoire dépend du contexte de
        // boutique du processus (ligne de commande, back-office) et une clé tout juste écrite peut y manquer.
        $existing = [];
        if ($defaults) {
            $rows = Db::getInstance()->executeS('SELECT DISTINCT name FROM `' . _DB_PREFIX_ . 'configuration` WHERE name IN (\''
                . implode('\',\'', array_map('pSQL', array_keys($defaults))) . '\')', true, false) ?: [];
            $existing = array_column($rows, 'name');
        }
        $ok = true;
        foreach (self::missingConfig($defaults, $existing) as $k => $v) {
            $ok = Configuration::updateValue($k, $v instanceof Closure ? $v() : $v) && $ok;
        }

        return $ok;
    }
}
