<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase
{
    public function testDureesParDefautDocumentees(): void
    {
        $this->assertSame([
            'UCPBRIDGE_RETENTION_SESSIONS' => 30,
            'UCPBRIDGE_RETENTION_GUESTS' => 30,
            'UCPBRIDGE_RETENTION_OAUTH' => 30,
            'UCPBRIDGE_RETENTION_PAYMENTS' => 3650,
            'UCPBRIDGE_RETENTION_PROOFS' => 3650,
        ], UcpBridgeRetention::defaults());
    }

    public function testSaisieDesDurees(): void
    {
        $this->assertSame(0, UcpBridgeRetention::normalizeDays('0'));
        $this->assertSame(45, UcpBridgeRetention::normalizeDays(' 45 '));
        $this->assertSame(36500, UcpBridgeRetention::normalizeDays('36500'));
        foreach (['', '-3', '3.5', 'abc', '36501', '1e3', '999999999'] as $v) {
            $this->assertNull(UcpBridgeRetention::normalizeDays($v), $v);
        }
    }

    public function testDateLimite(): void
    {
        $now = strtotime('2026-10-22 12:00:00');
        $this->assertSame(date('Y-m-d H:i:s', $now - 30 * 86400), UcpBridgeRetention::cutoff(30, $now));
        $this->assertNull(UcpBridgeRetention::cutoff(0, $now)); // 0 = jamais purgé
    }

    public function testDonneesPersonnellesRetireesIdentifiantsConserves(): void
    {
        $p = ['lines' => [['id' => 'li_1']], 'buyer' => ['email' => 'a@b.test'], 'consent' => ['marketing' => true],
            'destination' => ['street_address' => '1 rue'], 'terms_acceptance' => ['ip' => '192.0.2.1'], 'context' => ['x' => 1],
            'id_customer' => 12, 'id_address' => 34, 'id_carrier' => 2, 'transaction_id' => 'mock_1'];
        $m = UcpBridgeRetention::minimizePayload($p, strtotime('2026-10-22 12:00:00 UTC'));
        foreach (UcpBridgeRetention::PERSONAL_KEYS as $k) {
            $this->assertArrayNotHasKey($k, $m);
        }
        $this->assertSame([['id' => 'li_1']], $m['lines']);
        $this->assertSame(12, $m['id_customer']);
        $this->assertSame('mock_1', $m['transaction_id']);
        $this->assertSame('2026-10-22T12:00:00Z', $m['minimized_at']);
    }

    public function testCompteRendu(): void
    {
        $lines = UcpBridgeRetention::summary(['guests' => 2, 'sessions_deleted' => 5], false);
        $this->assertCount(count(UcpBridgeRetention::REPORT), $lines);
        $this->assertSame('Sessions et paniers UCP supprimés : 5', $lines[0]);
        $this->assertContains('Clients invités sans commande supprimés : 2', $lines);
        $this->assertContains('Clients invités sans commande à supprimer : 2', UcpBridgeRetention::summary(['guests' => 2], true));
    }

    public function testPlanchersDesDureesLegales(): void
    {
        // Journal des paiements, preuves et rétractations : 10 ans au moins, ou 0 (jamais purgé).
        $this->assertSame(['payments' => 3650, 'proofs' => 3650], UcpBridgeRetention::LEGAL_MIN_DAYS);
        foreach (['payments', 'proofs'] as $cat) {
            foreach ([1, 30, 365, 3649] as $days) {
                $this->assertTrue(UcpBridgeRetention::tooShort($cat, $days), $cat . ' ' . $days);
            }
            foreach ([0, 3650, 4000, UcpBridgeRetention::MAX_DAYS] as $days) {
                $this->assertFalse(UcpBridgeRetention::tooShort($cat, $days), $cat . ' ' . $days);
            }
        }
        foreach (['sessions', 'guests', 'oauth'] as $cat) {
            $this->assertFalse(UcpBridgeRetention::tooShort($cat, 1), $cat);
        }
        $this->assertSame(['proofs' => 365], UcpBridgeRetention::tooShortAll(['sessions' => 1, 'payments' => 0, 'proofs' => 365]));
    }
}
