<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SecondStay\Reporting\XlsxWriter;
use SecondStay\Tests\Support\XlsxReader;

/**
 * Écriture d'un classeur XLSX en PHP pur (SPECIFICATIONS.md §66).
 *
 * Chaque classeur est relu par `XlsxReader`, écrit indépendamment : un
 * aller-retour réussi prouve que le fichier est réellement conforme, et pas
 * seulement qu'il contient les bonnes chaînes.
 */
final class XlsxWriterTest extends TestCase
{
    private function reader(XlsxWriter $writer): XlsxReader
    {
        return new XlsxReader($writer->output());
    }

    public function testASheetIsReadBackWithItsHeadersAndRows(): void
    {
        $writer = new XlsxWriter();
        $writer->addSheet('Synthèse', ['Indicateur', 'Valeur'], [
            [XlsxWriter::text('Encaissé'), XlsxWriter::money(145_50)],
            [XlsxWriter::text('Nuits vendues'), XlsxWriter::number(12)],
        ]);

        $reader = $this->reader($writer);

        self::assertSame(['Synthèse'], $reader->sheetNames());
        self::assertSame(
            [['Indicateur', 'Valeur'], ['Encaissé', '145.5'], ['Nuits vendues', '12']],
            $reader->rows('Synthèse')
        );
    }

    public function testSeveralSheetsKeepTheirOrder(): void
    {
        $writer = new XlsxWriter();
        $writer->addSheet('Synthèse', ['A'], [[XlsxWriter::text('1')]]);
        $writer->addSheet('Séjours', ['B'], [[XlsxWriter::text('2')]]);

        $reader = $this->reader($writer);

        self::assertSame(['Synthèse', 'Séjours'], $reader->sheetNames());
        self::assertSame('2', $reader->cell('Séjours', 'A2'));
    }

    public function testAmountsCarryTheMonetaryFormat(): void
    {
        $writer = new XlsxWriter();
        $writer->addSheet('S', ['Montant', 'Nombre'], [
            [XlsxWriter::money(1_00), XlsxWriter::number(3)],
        ]);

        $reader = $this->reader($writer);

        self::assertTrue($reader->isMoney('S', 'A2'));
        self::assertFalse($reader->isMoney('S', 'B2'));
        self::assertFalse($reader->isMoney('S', 'A1'));
    }

    public function testAmountsAreWrittenInUnitsWithADecimalPoint(): void
    {
        $writer = new XlsxWriter();
        $writer->addSheet('S', ['Montant'], [
            [XlsxWriter::money(1_234_56)],
            [XlsxWriter::money(-50)],
            [XlsxWriter::money(0)],
        ]);

        $reader = $this->reader($writer);

        self::assertSame('1234.56', $reader->cell('S', 'A2'));
        self::assertSame('-0.5', $reader->cell('S', 'A3'));
        self::assertSame('0', $reader->cell('S', 'A4'));
    }

    public function testTextIsEscapedRatherThanInterpreted(): void
    {
        $writer = new XlsxWriter();
        $writer->addSheet('S', ['Objet'], [
            [XlsxWriter::text('Dupont & Fils <"référence">')],
        ]);

        self::assertSame('Dupont & Fils <"référence">', $this->reader($writer)->cell('S', 'A2'));
    }

    public function testLeadingAndTrailingSpacesSurvive(): void
    {
        $writer = new XlsxWriter();
        $writer->addSheet('S', ['Objet'], [[XlsxWriter::text('  espacé  ')]]);

        self::assertSame('  espacé  ', $this->reader($writer)->cell('S', 'A2'));
    }

    public function testColumnsBeyondZAreAddressedCorrectly(): void
    {
        $headers = [];
        $row = [];
        for ($index = 0; $index < 28; $index++) {
            $headers[] = 'H' . $index;
            $row[] = XlsxWriter::number($index);
        }

        $writer = new XlsxWriter();
        $writer->addSheet('S', $headers, [$row]);

        $reader = $this->reader($writer);

        self::assertSame('26', $reader->cell('S', 'AA2'));
        self::assertSame('27', $reader->cell('S', 'AB2'));
        self::assertSame('H27', $reader->cell('S', 'AB1'));
    }

    public function testTheSameDataAlwaysProducesTheSameBytes(): void
    {
        $build = static function (): string {
            $writer = new XlsxWriter();
            $writer->addSheet('S', ['A', 'B'], [
                [XlsxWriter::text('x'), XlsxWriter::money(999)],
            ]);

            return $writer->output();
        };

        // Deux exports du même mois doivent se comparer avec `diff` : un
        // classeur horodaté l'interdirait.
        self::assertSame(md5($build()), md5($build()));
    }

    public function testASheetNameIsSanitisedAndTruncated(): void
    {
        $writer = new XlsxWriter();
        $writer->addSheet('Compta/2026:[test]' . str_repeat('x', 40), ['A'], []);

        $name = $this->reader($writer)->sheetNames()[0];

        self::assertLessThanOrEqual(31, mb_strlen($name));
        foreach (['/', '\\', ':', '*', '?', '[', ']'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $name);
        }
    }

    public function testAnEmptyWorkbookIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        (new XlsxWriter())->output();
    }

    public function testASheetWithoutRowsStillCarriesItsHeaders(): void
    {
        $writer = new XlsxWriter();
        $writer->addSheet('S', ['Référence', 'Montant'], []);

        self::assertSame([['Référence', 'Montant']], $this->reader($writer)->rows('S'));
    }

    public function testTheArchiveCarriesTheExpectedParts(): void
    {
        $writer = new XlsxWriter();
        $writer->addSheet('S', ['A'], [[XlsxWriter::text('x')]]);

        $bytes = $writer->output();

        // Signature ZIP : ce que tout tableur cherche en premier.
        self::assertStringStartsWith("PK\x03\x04", $bytes);
        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            self::assertStringContainsString($part, $bytes);
        }
    }
}
