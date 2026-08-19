<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Support\Slugger;

final class SluggerTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function values(): array
    {
        return [
            ['Le Logement', 'le-logement'],
            ['  Le Logement — Été  ', 'le-logement-ete'],
            ['Activités 2026', 'activites-2026'],
            ['Extérieur / Jardin', 'exterieur-jardin'],
            ['Größe', 'grosse'],
            ['Skiën', 'skien'],
            ['Cœur de village', 'coeur-de-village'],
            ['already-a-slug', 'already-a-slug'],
            ['---', ''],
            ['', ''],
            ['A  B   C', 'a-b-c'],
        ];
    }

    #[DataProvider('values')]
    public function testSlugsAreAsciiAndStable(string $input, string $expected): void
    {
        self::assertSame($expected, Slugger::slug($input));
    }

    public function testSlugsAreTruncated(): void
    {
        self::assertSame(10, mb_strlen(Slugger::slug(str_repeat('a', 200), 10)));
    }

    public function testSlugContainsOnlySafeCharacters(): void
    {
        self::assertMatchesRegularExpression('/^[a-z0-9-]*$/', Slugger::slug('Ünïcödé ☃ 2026 !'));
    }
}
