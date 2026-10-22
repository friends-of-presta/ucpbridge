<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase
{
    public function testFormulesNeutralisees(): void
    {
        foreach (['=1+1', '+33', '-2', '@SOMME(A1)', "\tx", "\rx"] as $v) {
            $this->assertSame("'" . $v, UcpBridgeExport::cell($v), $v);
        }
        $this->assertSame('profile="https://agent.test"', UcpBridgeExport::cell('profile="https://agent.test"'));
        $this->assertSame('', UcpBridgeExport::cell(null));
        $this->assertSame('12', UcpBridgeExport::cell(12));
    }

    public function testLigneRfc4180PointVirgule(): void
    {
        $this->assertSame("a;b;;3\r\n", UcpBridgeExport::line(['a', 'b', null, 3]));
        $this->assertSame("\"x;y\";\"il a dit \"\"oui\"\"\";\"l1\nl2\"\r\n", UcpBridgeExport::line(['x;y', 'il a dit "oui"', "l1\nl2"]));
        // Formule neutralisée puis mise entre guillemets si besoin.
        $this->assertSame("\"'=A1;B1\"\r\n", UcpBridgeExport::line(['=A1;B1']));
    }

    public function testCellulesDansLOrdreDeLEntete(): void
    {
        $row = ['w_email' => 'a@b.test', 'id_order' => '7', 'links' => '[{"type":"terms_of_service"}]'];
        $cells = UcpBridgeExport::cells($row);
        $this->assertCount(count(UcpBridgeExport::COLUMNS), $cells);
        $this->assertSame('7', $cells[0]);
        $this->assertSame('[{"type":"terms_of_service"}]', $cells[10]);
        $this->assertSame('a@b.test', $cells[15]);
        $this->assertSame('', $cells[1]);
    }

    public function testNomDeFichierDate(): void
    {
        $this->assertMatchesRegularExpression('/^ucpbridge-preuves-\d{8}-\d{6}\.csv$/', UcpBridgeExport::filename(time()));
    }
}
