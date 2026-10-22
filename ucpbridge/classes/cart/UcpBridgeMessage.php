<?php
/**
 * Messages UCP renvoyés aux agents et enveloppes des réponses (logique pure). Le code (`code`) est stable et sert aux
 * agents ; le texte (`content`) est lisible par un humain.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeMessage
{
    /** Message d'erreur UCP : code stable, texte lisible, gravité, chemin JSON facultatif, champs supplémentaires. */
    public static function error($code, $content, $severity = 'recoverable', $path = null, array $extra = [])
    {
        $m = ['type' => 'error', 'code' => $code, 'content' => $content, 'severity' => $severity];
        if ($path) {
            $m['path'] = $path;
        }

        return $m + $extra;
    }

    public static function info($code, $content)
    {
        return ['type' => 'info', 'code' => $code, 'content' => $content];
    }

    /** Capacités annoncées dans l'enveloppe `ucp`, toutes à la version du module. */
    public static function capabilities(array $names, $version)
    {
        $caps = [];
        foreach ($names as $c) {
            $caps[$c] = [['version' => $version]];
        }

        return $caps;
    }

    /** Enveloppe `ucp` d'une réponse réussie ; gestionnaires de paiement ajoutés pour la caisse. */
    public static function envelope(array $capabilities, $version, ?array $paymentHandlers = null)
    {
        $e = ['version' => $version, 'status' => 'success', 'capabilities' => self::capabilities($capabilities, $version)];
        if (null !== $paymentHandlers) {
            $e['payment_handlers'] = (object) $paymentHandlers;
        }

        return $e;
    }

    /** Document d'erreur UCP : enveloppe en statut error, messages, lien de retour vers la boutique. */
    public static function errorDoc(array $messages, array $capabilities, $version, $continueUrl)
    {
        return [
            'ucp' => ['version' => $version, 'status' => 'error', 'capabilities' => self::capabilities($capabilities, $version)],
            'messages' => $messages,
            'continue_url' => $continueUrl,
        ];
    }
}
