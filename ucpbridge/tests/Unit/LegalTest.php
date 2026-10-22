<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class LegalTest extends TestCase
{
    private function pages(): array
    {
        return [
            1 => ['title' => 'Livraison', 'rewrite' => 'livraison', 'url' => 'http://b/content/1-livraison', 'active' => true],
            2 => ['title' => 'Mentions légales', 'rewrite' => 'mentions-legales', 'url' => 'http://b/content/2-mentions-legales', 'active' => true],
            3 => ['title' => 'Conditions d\'utilisation', 'rewrite' => 'conditions-utilisation', 'url' => 'http://b/content/3-conditions-utilisation', 'active' => true],
            4 => ['title' => 'A propos', 'rewrite' => 'a-propos', 'url' => 'http://b/content/4-a-propos', 'active' => true],
            5 => ['title' => 'Paiement sécurisé', 'rewrite' => 'paiement-securise', 'url' => 'http://b/content/5', 'active' => true],
        ];
    }

    public function testCorrespondanceParDefautDeLaDemo(): void
    {
        $m = UcpBridgeLegal::defaultMapping($this->pages(), 3);
        $this->assertSame(['terms_of_service' => 3, 'privacy_policy' => 2, 'refund_policy' => 3, 'shipping_policy' => 1], $m);
    }

    public function testCorrespondanceParMotsCles(): void
    {
        $pages = [
            7 => ['title' => 'CGV', 'rewrite' => 'cgv', 'url' => 'u7', 'active' => true],
            8 => ['title' => 'Politique de confidentialité', 'rewrite' => 'politique-confidentialite', 'url' => 'u8', 'active' => true],
            9 => ['title' => 'Retours et remboursements', 'rewrite' => 'retours', 'url' => 'u9', 'active' => true],
        ];
        $m = UcpBridgeLegal::defaultMapping($pages, 0);
        $this->assertSame(['terms_of_service' => 7, 'privacy_policy' => 8, 'refund_policy' => 9, 'shipping_policy' => 0], $m);
    }

    public function testLiensDansLOrdreAvecTitre(): void
    {
        $links = UcpBridgeLegal::links(['terms_of_service' => 3, 'privacy_policy' => 2, 'refund_policy' => 3, 'shipping_policy' => 1], $this->pages());
        $this->assertSame(['terms_of_service', 'privacy_policy', 'refund_policy', 'shipping_policy'], array_column($links, 'type'));
        $this->assertSame('Conditions d\'utilisation', $links[0]['title']);
        $this->assertSame('http://b/content/1-livraison', $links[3]['url']);
    }

    public function testPagesAbsentesOuInactivesIgnorees(): void
    {
        $pages = $this->pages();
        $pages[1]['active'] = false;
        $links = UcpBridgeLegal::links(['terms_of_service' => 3, 'privacy_policy' => 99, 'refund_policy' => 0, 'shipping_policy' => 1], $pages);
        $this->assertSame(['terms_of_service'], array_column($links, 'type'));
    }

    public function testUnionEuropeenne(): void
    {
        $this->assertTrue(UcpBridgeLegal::isEu('fr'));
        $this->assertTrue(UcpBridgeLegal::isEu('BE'));
        $this->assertFalse(UcpBridgeLegal::isEu('CH'));
        $this->assertFalse(UcpBridgeLegal::isEu('GB'));
        $this->assertFalse(UcpBridgeLegal::isEu(''));
        $this->assertCount(27, UcpBridgeLegal::EU);
    }

    public function testMentionDeRetractation(): void
    {
        $m = UcpBridgeLegal::withdrawalDisclosure('FR', 'http://b/content/3');
        $this->assertSame('warning', $m['type']);
        $this->assertSame('disclosure', $m['presentation']);
        $this->assertSame('withdrawal_right', $m['code']);
        $this->assertStringContainsString('14', $m['content']); // texte lisible traduit : seul le délai est vérifié
        $this->assertSame('http://b/content/3', $m['url']);
        $this->assertNull(UcpBridgeLegal::withdrawalDisclosure('CH', 'x'));
    }

    public function testVersionDesCgvStable(): void
    {
        $a = UcpBridgeLegal::termsVersion(3, '2026-10-01 10:00:00', '<p>Texte</p>');
        $this->assertSame($a, UcpBridgeLegal::termsVersion(3, '2026-10-01 10:00:00', '<p>Texte</p>'));
        $this->assertNotSame($a, UcpBridgeLegal::termsVersion(3, '2026-10-01 10:00:00', '<p>Texte modifié</p>'));
        $this->assertMatchesRegularExpression('/^cms3-[0-9a-f]{12}$/', $a);
    }
}
