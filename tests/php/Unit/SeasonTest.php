<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Content\PageKind;
use SecondStay\Content\Season;

final class SeasonTest extends TestCase
{
    /**
     * @return list<array{string, Season}>
     */
    public static function months(): array
    {
        return [
            ['2026-01-15', Season::Winter],
            ['2026-02-15', Season::Winter],
            ['2026-03-15', Season::Winter],
            ['2026-04-15', Season::Summer],
            ['2026-07-15', Season::Summer],
            ['2026-10-15', Season::Summer],
            ['2026-11-15', Season::Winter],
            ['2026-12-15', Season::Winter],
        ];
    }

    #[DataProvider('months')]
    public function testCurrentSeasonFollowsTheMonth(string $date, Season $expected): void
    {
        self::assertSame($expected, Season::current(new DateTimeImmutable($date)));
    }

    public function testConfiguredSeasonOverridesTheDate(): void
    {
        $july = new DateTimeImmutable('2026-07-15');

        self::assertSame(Season::Summer, Season::resolve('auto', $july));
        self::assertSame(Season::Winter, Season::resolve('winter', $july));
        self::assertSame(Season::Summer, Season::resolve('summer', new DateTimeImmutable('2026-01-15')));
    }

    public function testUnknownConfigurationFallsBackToAutomatic(): void
    {
        self::assertSame(Season::Winter, Season::resolve('printemps', new DateTimeImmutable('2026-01-15')));
        self::assertSame(Season::Winter, Season::resolve('all', new DateTimeImmutable('2026-01-15')));
    }

    public function testVisibilityRules(): void
    {
        self::assertTrue(Season::All->matches(Season::Summer));
        self::assertTrue(Season::All->matches(Season::Winter));
        self::assertTrue(Season::Summer->matches(Season::Summer));
        self::assertFalse(Season::Summer->matches(Season::Winter));
        self::assertFalse(Season::Winter->matches(Season::Summer));
    }

    public function testFromStringIsForgiving(): void
    {
        self::assertSame(Season::Summer, Season::fromString(' SUMMER '));
        self::assertSame(Season::All, Season::fromString('inconnu'));
    }

    public function testPageKindTemplates(): void
    {
        self::assertSame('public/home.html.twig', PageKind::Home->template());
        self::assertSame('public/gallery.html.twig', PageKind::Gallery->template());
        self::assertSame('public/contact.html.twig', PageKind::Contact->template());
        self::assertSame('public/page.html.twig', PageKind::Page->template());
        self::assertSame('public/page.html.twig', PageKind::Legal->template());

        self::assertTrue(PageKind::Legal->isLegal());
        self::assertFalse(PageKind::Page->isLegal());
        self::assertSame(PageKind::Page, PageKind::fromString('inconnu'));
    }
}
