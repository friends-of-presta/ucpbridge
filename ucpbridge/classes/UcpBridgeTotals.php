<?php
/**
 * Construction et contrôle des totaux UCP (logique pure, montants en centimes).
 * Règle : la somme des lignes autres que « total » égale le total.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeTotals
{
    /**
     * Caisse : sous-total HT, réductions HT (ce que la boutique retranche réellement), livraison HT, TVA, total TTC.
     *
     * @param int|null $shipHt null tant qu'aucun transporteur n'est choisi
     */
    public static function checkout($subHt, $shipHt, $totalHt, $totalTtc, $country)
    {
        $discHt = max(0, (int) $subHt + (int) $shipHt - (int) $totalHt);
        $t = [['type' => 'subtotal', 'amount' => (int) $subHt, 'display_text' => UcpBridgeI18n::api('Subtotal excl. tax')]];
        if ($discHt > 0) {
            $t[] = ['type' => 'discount', 'amount' => -$discHt, 'display_text' => UcpBridgeI18n::api('Discounts excl. tax')];
        }
        if (null !== $shipHt) {
            $t[] = ['type' => 'fulfillment', 'amount' => (int) $shipHt, 'display_text' => UcpBridgeI18n::api('Shipping excl. tax')];
        }
        $t[] = ['type' => 'tax', 'amount' => (int) $totalTtc - (int) $totalHt,
            'display_text' => $country ? UcpBridgeI18n::api('VAT (%country%)', ['%country%' => $country]) : UcpBridgeI18n::api('VAT')];
        $t[] = ['type' => 'total', 'amount' => (int) $totalTtc, 'display_text' => UcpBridgeI18n::api('Total incl. tax')];

        return $t;
    }

    /** Commande passée : montants relus sur la commande PrestaShop. */
    public static function order($subHt, $shipHt, $discHt, $totalTtc)
    {
        $t = [['type' => 'subtotal', 'amount' => (int) $subHt, 'display_text' => UcpBridgeI18n::api('Subtotal excl. tax')]];
        if ((int) $discHt) {
            $t[] = ['type' => 'discount', 'amount' => -abs((int) $discHt), 'display_text' => UcpBridgeI18n::api('Discounts excl. tax')];
        }
        $t[] = ['type' => 'fulfillment', 'amount' => (int) $shipHt, 'display_text' => UcpBridgeI18n::api('Shipping excl. tax')];
        $t[] = ['type' => 'tax', 'amount' => (int) $totalTtc - (int) $subHt + abs((int) $discHt) - (int) $shipHt, 'display_text' => UcpBridgeI18n::api('VAT')];
        $t[] = ['type' => 'total', 'amount' => (int) $totalTtc, 'display_text' => UcpBridgeI18n::api('Total incl. tax')];

        return $t;
    }

    /** Panier transférable : montants TTC, livraison calculée à la caisse. */
    public static function cart($subTtc, $totalTtc)
    {
        $t = [['type' => 'subtotal', 'amount' => (int) $subTtc, 'display_text' => UcpBridgeI18n::api('Subtotal incl. tax')]];
        if ((int) $subTtc - (int) $totalTtc > 0) {
            $t[] = ['type' => 'discount', 'amount' => (int) $totalTtc - (int) $subTtc, 'display_text' => UcpBridgeI18n::api('Discounts incl. tax')];
        }
        $t[] = ['type' => 'total', 'amount' => (int) $totalTtc, 'display_text' => UcpBridgeI18n::api('Estimated total incl. tax (shipping calculated at checkout)')];

        return $t;
    }

    public static function isConsistent(array $totals)
    {
        $tot = array_values(array_filter($totals, function ($t) {
            return 'total' === ($t['type'] ?? '');
        }));
        if (1 !== count($tot)) {
            return false;
        }
        $sum = 0;
        foreach ($totals as $t) {
            if ('total' !== ($t['type'] ?? '')) {
                $sum += (int) $t['amount'];
            }
        }

        return $sum === (int) $tot[0]['amount'];
    }
}
