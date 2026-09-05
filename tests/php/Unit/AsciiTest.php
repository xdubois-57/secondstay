<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Support\Ascii;

final class AsciiTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function values(): array
    {
        return [
            ['Été Indien', 'Ete Indien'],
            ['Größe', 'Grosse'],
            ['Cœur de village', 'Coeur de village'],
            ['Skiën', 'Skien'],
            ['Æblegård', 'AEblegard'],
            ['Łódź', 'Lodz'],
            ['IJsselmeer', 'IJsselmeer'],
            ['déjà-vu', 'deja-vu'],
            ['already ascii 123', 'already ascii 123'],
            ['', ''],
        ];
    }

    #[DataProvider('values')]
    public function testLatinIsFoldedWithoutPunctuationArtefacts(string $input, string $expected): void
    {
        self::assertSame($expected, Ascii::fold($input));
    }

    /**
     * Le défaut d'origine : la libiconv de macOS et des BSD rend « Été » par
     * « 'Et'e », ce qui coupait le mot en deux et donnait « EE » au lieu de
     * « EI » pour les initiales de « Été Indien ».
     */
    public function testNoQuoteIsIntroducedWhereTheSourceHadNone(): void
    {
        self::assertStringNotContainsString("'", Ascii::fold('Été à Nîmes'));
        self::assertStringNotContainsString('"', Ascii::fold('Größe für Ölöfen'));
    }

    public function testTypographyBecomesItsAsciiEquivalent(): void
    {
        self::assertSame('" Le Logement " - Ete...', Ascii::fold("«\u{202F}Le Logement\u{202F}» — Été…"));
    }

    public function testUnknownCharactersDisappearRatherThanBecomingNoise(): void
    {
        self::assertSame(' 2026 ', Ascii::fold('☃ 2026 привет'));
        self::assertSame('abc', Ascii::fold("a\xFF\xFEbc"));
    }

    public function testOutputIsAlwaysPrintableAscii(): void
    {
        self::assertMatchesRegularExpression('/^[\x20-\x7e]*$/', Ascii::fold("Ünïcödé ☃\t2026 !"));
    }
}
