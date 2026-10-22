<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testColonnesManquantesDansLOrdreDeDeclaration(): void
    {
        $declared = ['a' => 'INT NOT NULL DEFAULT 0', 'b' => 'VARCHAR(8) NULL', 'c' => 'TEXT NULL'];
        $this->assertSame(['a' => 'INT NOT NULL DEFAULT 0', 'c' => 'TEXT NULL'], UcpBridgeSchema::missingColumns($declared, ['id', 'b']));
    }

    public function testRejouableQuandToutEstDejaLa(): void
    {
        $declared = ['issued_us' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0'];
        $this->assertSame([], UcpBridgeSchema::missingColumns($declared, ['token_hash', 'issued_us']));
        // MySQL ne distingue pas la casse des noms de colonnes.
        $this->assertSame([], UcpBridgeSchema::missingColumns($declared, ['ISSUED_US']));
    }

    public function testInstructionDAjout(): void
    {
        $this->assertSame('ALTER TABLE `ps_ucpbridge_session` ADD COLUMN `x_y` INT NOT NULL DEFAULT 0',
            UcpBridgeSchema::addColumnSql('ps_ucpbridge_session', 'x_y', 'INT NOT NULL DEFAULT 0'));
    }

    public function testIdentifiantInvalideRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UcpBridgeSchema::addColumnSql('ps_ucpbridge_session', 'x`; DROP TABLE ps_orders; --', 'INT');
    }

    public function testConfigurationManquanteSeulement(): void
    {
        $defaults = ['UCPBRIDGE_A' => '', 'UCPBRIDGE_B' => 0, 'UCPBRIDGE_C' => '{}'];
        $this->assertSame(['UCPBRIDGE_B' => 0], UcpBridgeSchema::missingConfig($defaults, ['UCPBRIDGE_A', 'UCPBRIDGE_C', 'PS_SHOP_NAME']));
        $this->assertSame([], UcpBridgeSchema::missingConfig($defaults, array_keys($defaults)));
    }
}
