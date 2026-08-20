<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Pdf\PdfDocument;
use SecondStay\Pdf\PdfFont;
use SecondStay\Pdf\WinAnsi;
use SecondStay\Tests\Support\PdfReader;

/**
 * Générateur PDF.
 *
 * Un PDF qu'aucun lecteur n'ouvre serait un contrat perdu : chaque cas relit
 * donc le fichier produit avec un lecteur indépendant — table des références,
 * décalages d'objets, longueurs de flux, décompression — plutôt que de
 * chercher une chaîne dans les octets.
 */
final class PdfDocumentTest extends TestCase
{
    public function testTheFileIsStructurallyValid(): void
    {
        $reader = new PdfReader($this->simple());

        self::assertSame('1.4', $reader->version());
        self::assertTrue($reader->endsProperly());
        self::assertSame(1, $reader->pageCount());
        self::assertCount(1, $reader->pageObjects());
    }

    public function testEveryDeclaredOffsetPointsAtItsObject(): void
    {
        // Le lecteur lève une exception si un décalage ne retombe pas sur
        // l'en-tête « N 0 obj » attendu : la seule construction suffit donc à
        // valider toute la table des références.
        $reader = new PdfReader($this->simple());

        self::assertNotSame([], $reader->objectNumbers());
        self::assertSame(range(1, count($reader->objectNumbers())), $reader->objectNumbers());
    }

    public function testTextIsReadBackInOrder(): void
    {
        $document = new PdfDocument(['title' => 'Contrat']);
        $document->addPage();
        $document->title('Contrat de location');
        $document->heading('Les parties');
        $document->paragraph('Le propriétaire et le voyageur conviennent de ce qui suit.');
        $document->keyValue('Total', '1 234,56 €', true);

        $text = (new PdfReader($document->output()))->text();

        self::assertStringContainsString('Contrat de location', $text);
        self::assertStringContainsString('Les parties', $text);
        self::assertStringContainsString('Le propriétaire et le voyageur', $text);
        self::assertStringContainsString('1 234,56 €', $text);

        self::assertLessThan(
            strpos($text, 'Les parties'),
            strpos($text, 'Contrat de location'),
            'Le titre doit précéder la première section.'
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function accentedTexts(): array
    {
        return [
            ['Séjour à Chamonix — arrivée le 4 juillet'],
            ['Größe des Ferienhauses: 120 m²'],
            ['Verblijf in de Franse Alpen — sleutels'],
            ['Cœur de village, 350,00 € l’acompte'],
            ['« Guillemets » et apostrophe typographique’'],
            ['Ærø, Ångström, ñ, ç, ü, ß, ø'],
        ];
    }

    #[DataProvider('accentedTexts')]
    public function testTheFourLanguagesSurviveTheRoundTrip(string $text): void
    {
        $document = new PdfDocument();
        $document->addPage();
        $document->paragraph($text);

        self::assertStringContainsString($text, (new PdfReader($document->output()))->text());
    }

    public function testCharactersOutsideTheEncodingAreTransliteratedRatherThanLost(): void
    {
        $document = new PdfDocument();
        $document->addPage();
        // Le grec n'a pas de place dans WinAnsi : il ne doit pas produire un
        // fichier corrompu ni un caractère invisible.
        $document->paragraph('Delta grec : Δ fin');

        $text = (new PdfReader($document->output()))->text();

        self::assertStringContainsString('Delta grec :', $text);
        self::assertStringContainsString('fin', $text);
        self::assertStringNotContainsString('Δ', $text);
    }

    public function testLongTextFlowsOntoSeveralPages(): void
    {
        $document = new PdfDocument();
        $document->addPage();
        for ($index = 0; $index < 200; $index++) {
            $document->paragraph('Clause numéro ' . $index . ' du contrat de location saisonnière.');
        }

        $reader = new PdfReader($document->output());

        self::assertGreaterThan(1, $reader->pageCount());
        self::assertCount($reader->pageCount(), $reader->pageObjects());

        $text = $reader->text();
        self::assertStringContainsString('Clause numéro 0 ', $text);
        self::assertStringContainsString('Clause numéro 199 ', $text);
    }

    public function testAParagraphWiderThanThePageIsWrapped(): void
    {
        $document = new PdfDocument();
        $document->addPage();
        $document->paragraph(str_repeat('mot ', 200));

        $lines = explode("\n", (new PdfReader($document->output()))->text());

        self::assertGreaterThan(1, count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(
                $document->contentWidth() + 0.5,
                PdfFont::Regular->widthOf(WinAnsi::encode($line)) * 10.0 / 1000,
                'Aucune ligne ne doit dépasser la largeur utile.'
            );
        }
    }

    public function testAWordLongerThanTheLineIsStillEmitted(): void
    {
        $document = new PdfDocument();
        $document->addPage();
        $document->paragraph(str_repeat('M', 400));

        self::assertStringContainsString(str_repeat('M', 400), (new PdfReader($document->output()))->text());
    }

    public function testTablesKeepTheirCells(): void
    {
        $document = new PdfDocument();
        $document->addPage();
        $document->table(
            ['Composant', 'Échéance', 'Montant'],
            [
                ['Acompte', '04/07/2026', '350,00 €'],
                ['Solde', '04/06/2026', '816,00 €'],
                ['Caution', '04/06/2026', '500,00 €'],
            ],
            [2.0, 1.0, 1.0]
        );

        $text = (new PdfReader($document->output()))->text();

        foreach (['Composant', 'Échéance', 'Montant', 'Acompte', '350,00 €', 'Caution', '500,00 €'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
    }

    public function testOnlyStandardFontsAreDeclared(): void
    {
        $reader = new PdfReader($this->simple());
        $page = $reader->pageObjects()[0];

        self::assertSame(['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique'], $reader->fontsOfPage($page));
        self::assertStringContainsString('/WinAnsiEncoding', $reader->object(3));
        self::assertStringNotContainsString('/FontFile', $reader->object(3), 'Aucune police embarquée.');
    }

    public function testContentStreamsAreCompressed(): void
    {
        $document = new PdfDocument();
        $document->addPage();
        for ($index = 0; $index < 40; $index++) {
            $document->paragraph('Une ligne répétitive qui se comprime très bien.');
        }

        $raw = $document->output();
        $reader = new PdfReader($raw);

        // Le lecteur vérifie /Length puis décompresse : s'il y parvient, le
        // flux est réellement du FlateDecode conforme.
        $content = $reader->contentOfPage($reader->pageObjects()[0]);

        self::assertStringContainsString('/FlateDecode', $raw);
        self::assertGreaterThan(strlen($raw), strlen($content));
    }

    public function testTheDocumentInformationIsCarried(): void
    {
        $document = new PdfDocument([
            'title' => 'Contrat SS-2026-0001',
            'author' => 'Maison des Pins',
            'subject' => 'Location saisonnière',
            'date' => '20260704120000',
        ]);
        $document->addPage();
        $document->paragraph('x');

        $info = (new PdfReader($document->output()))->info();

        self::assertStringContainsString('Contrat SS-2026-0001', $info);
        self::assertStringContainsString('Maison des Pins', $info);
        self::assertStringContainsString('D:20260704120000Z', $info);
    }

    public function testTwoGenerationsWithTheSameInputAreIdentical(): void
    {
        // Un contrat est un instantané : le régénérer ne doit pas produire un
        // fichier différent, sans quoi aucune empreinte ne serait stable.
        self::assertSame($this->simple(), $this->simple());
    }

    public function testParenthesesAndBackslashesAreEscaped(): void
    {
        $document = new PdfDocument();
        $document->addPage();
        $document->paragraph('Clause (importante) : chemin C:\\Users\\test');

        $text = (new PdfReader($document->output()))->text();

        self::assertStringContainsString('Clause (importante)', $text);
        self::assertStringContainsString('C:\\Users\\test', $text);
    }

    private function simple(): string
    {
        $document = new PdfDocument([
            'title' => 'Contrat',
            'author' => 'SecondStay',
            'date' => '20260101000000',
        ]);
        $document->addPage();
        $document->title('Contrat');
        $document->paragraph('Texte de contrôle.');

        return $document->output();
    }
}
