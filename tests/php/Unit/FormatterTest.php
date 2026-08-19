<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Locales;

final class FormatterTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function locales(): array
    {
        return array_map(static fn (string $locale): array => [$locale], Locales::ALL);
    }

    #[DataProvider('locales')]
    public function testMoneyIsAlwaysFormattedInEuro(string $locale): void
    {
        $formatted = (new Formatter($locale))->money(123456);

        self::assertStringContainsString('€', $formatted);
        // La logique financière reste canonique : 123456 centimes = 1234,56 €.
        $digits = preg_replace('/[^0-9]/u', '', $formatted);
        self::assertSame('123456', $digits);
    }

    public function testFrenchAndGermanUseCommaDecimalSeparator(): void
    {
        self::assertStringContainsString('1 234,56', str_replace("\u{00A0}", ' ', (new Formatter('fr'))->money(123456)));
        self::assertStringContainsString('1.234,56', (new Formatter('de'))->money(123456));
    }

    public function testEnglishUsesDotDecimalSeparator(): void
    {
        self::assertStringContainsString('1,234.56', (new Formatter('en'))->money(123456));
    }

    #[DataProvider('locales')]
    public function testDatesAreLocalised(string $locale): void
    {
        $date = new DateTimeImmutable('2026-07-04 16:00:00', new \DateTimeZone('Europe/Paris'));
        $formatted = (new Formatter($locale))->date($date);

        self::assertNotSame('', $formatted);
        self::assertStringContainsString('2026', $formatted);
    }

    public function testFrenchLongDateContainsMonthName(): void
    {
        $date = new DateTimeImmutable('2026-07-04 16:00:00', new \DateTimeZone('Europe/Paris'));
        self::assertStringContainsString('juillet', (new Formatter('fr'))->date($date, 'long'));
        self::assertStringContainsString('Juli', (new Formatter('de'))->date($date, 'long'));
        self::assertStringContainsString('juli', (new Formatter('nl'))->date($date, 'long'));
        self::assertStringContainsString('July', (new Formatter('en'))->date($date, 'long'));
    }

    public function testTimeUsesConfiguredTimezone(): void
    {
        $date = new DateTimeImmutable('2026-07-04T14:00:00+00:00');
        $formatted = (new Formatter('fr', 'Europe/Paris'))->time($date);

        self::assertStringContainsString('16', $formatted);
    }

    public function testNumberFormatting(): void
    {
        self::assertSame('12,50', str_replace("\u{202F}", ' ', (new Formatter('fr'))->number(12.5)));
        self::assertSame('12.50', (new Formatter('en'))->number(12.5));
    }

    public function testWithLocaleReturnsNewInstance(): void
    {
        $formatter = new Formatter('fr');
        $german = $formatter->withLocale('de');

        self::assertSame('fr', $formatter->locale());
        self::assertSame('de', $german->locale());
    }
}
