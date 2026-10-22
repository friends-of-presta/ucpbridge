<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testErreurAvecCheminEtChampsSupplementaires(): void
    {
        $m = UcpBridgeMessage::error('variant_required', 'texte', 'recoverable', '$.line_items[0].item', ['variants' => ['a']]);
        $this->assertSame(['type' => 'error', 'code' => 'variant_required', 'content' => 'texte', 'severity' => 'recoverable',
            'path' => '$.line_items[0].item', 'variants' => ['a']], $m);
    }

    public function testErreurSansChemin(): void
    {
        $m = UcpBridgeMessage::error('not_found', 'texte', 'unrecoverable');
        $this->assertArrayNotHasKey('path', $m);
        $this->assertSame('unrecoverable', $m['severity']);
    }

    public function testDocumentDErreur(): void
    {
        $d = UcpBridgeMessage::errorDoc([UcpBridgeMessage::error('x', 'y')], ['dev.ucp.shopping.cart'], '2026-04-08', 'https://b.test/');
        $this->assertSame('error', $d['ucp']['status']);
        $this->assertSame([['version' => '2026-04-08']], $d['ucp']['capabilities']['dev.ucp.shopping.cart']);
        $this->assertSame('x', $d['messages'][0]['code']);
        $this->assertSame('https://b.test/', $d['continue_url']);
    }

    public function testEnveloppeDeCaisseAvecGestionnaires(): void
    {
        $e = UcpBridgeMessage::envelope(['dev.ucp.shopping.checkout'], '2026-04-08', []);
        $this->assertSame('success', $e['status']);
        $this->assertSame('{}', json_encode($e['payment_handlers']));
        $this->assertArrayNotHasKey('payment_handlers', UcpBridgeMessage::envelope(['dev.ucp.shopping.cart'], '2026-04-08'));
    }
}
