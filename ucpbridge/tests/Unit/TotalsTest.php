<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class TotalsTest extends TestCase
{
    public function testCaisseSansRemise(): void
    {
        $t = UcpBridgeTotals::checkout(1000, 700, 1700, 2040, 'FR');
        $this->assertSame(['subtotal', 'fulfillment', 'tax', 'total'], array_column($t, 'type'));
        $this->assertSame(340, $t[2]['amount']);
        $this->assertStringContainsString('FR', $t[2]['display_text']); // pays dans le libellé de la taxe
        $this->assertTrue(UcpBridgeTotals::isConsistent($t));
    }

    public function testCaisseAvecRemiseEtLivraisonOfferte(): void
    {
        // 30 € HT de produits, -3 € de code, livraison 7 € offerte : total HT 27 €
        $t = UcpBridgeTotals::checkout(3000, 700, 2700, 3240, 'BE');
        $this->assertSame(-1000, $t[1]['amount']);
        $this->assertSame('discount', $t[1]['type']);
        $this->assertTrue(UcpBridgeTotals::isConsistent($t));
    }

    public function testCaisseSansTransporteur(): void
    {
        $t = UcpBridgeTotals::checkout(1000, null, 1000, 1200, null);
        $this->assertSame(['subtotal', 'tax', 'total'], array_column($t, 'type'));
        $this->assertSame('tax', $t[1]['type']);
        $this->assertNotSame('', $t[1]['display_text']);
        $this->assertTrue(UcpBridgeTotals::isConsistent($t));
    }

    public function testCommande(): void
    {
        $t = UcpBridgeTotals::order(1673, 700, 0, 2848);
        $this->assertSame(475, $t[2]['amount']);
        $this->assertTrue(UcpBridgeTotals::isConsistent($t));
        $t = UcpBridgeTotals::order(3000, 700, 1000, 3240);
        $this->assertSame(-1000, $t[1]['amount']);
        $this->assertTrue(UcpBridgeTotals::isConsistent($t));
    }

    public function testPanier(): void
    {
        $t = UcpBridgeTotals::cart(2400, 2160);
        $this->assertSame([2400, -240, 2160], array_column($t, 'amount'));
        $this->assertTrue(UcpBridgeTotals::isConsistent($t));
        $this->assertCount(2, UcpBridgeTotals::cart(2400, 2400));
    }

    public function testIncoherenceDetectee(): void
    {
        $this->assertFalse(UcpBridgeTotals::isConsistent([['type' => 'subtotal', 'amount' => 10], ['type' => 'total', 'amount' => 11]]));
        $this->assertFalse(UcpBridgeTotals::isConsistent([['type' => 'subtotal', 'amount' => 10]]));
        $this->assertFalse(UcpBridgeTotals::isConsistent([['type' => 'total', 'amount' => 0], ['type' => 'total', 'amount' => 0]]));
    }
}
