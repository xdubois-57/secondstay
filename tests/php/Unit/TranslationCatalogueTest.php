<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\I18n\Locales;
use SecondStay\I18n\Translator;

/**
 * Garde-fou i18n : aucune clé FR ne peut exister sans équivalent EN/NL/DE.
 */
final class TranslationCatalogueTest extends TestCase
{
    private static function translationsPath(): string
    {
        return dirname(__DIR__, 3) . '/translations';
    }

    private static function translator(): Translator
    {
        return new Translator(self::translationsPath());
    }

    /**
     * @return list<array{string}>
     */
    public static function locales(): array
    {
        return array_map(static fn (string $locale): array => [$locale], Locales::ALL);
    }

    #[DataProvider('locales')]
    public function testLocaleDirectoryExists(string $locale): void
    {
        self::assertDirectoryExists(self::translationsPath() . '/' . $locale);
    }

    #[DataProvider('locales')]
    public function testCatalogueHasSameKeysAsReference(string $locale): void
    {
        $reference = self::translator()->catalogue(Locales::FALLBACK);
        $catalogue = self::translator()->catalogue($locale);

        $missing = array_diff(array_keys($reference), array_keys($catalogue));
        self::assertSame([], array_values($missing), 'Clés manquantes en ' . $locale);

        $extra = array_diff(array_keys($catalogue), array_keys($reference));
        self::assertSame([], array_values($extra), 'Clés en trop en ' . $locale);
    }

    #[DataProvider('locales')]
    public function testPlaceholdersAreConsistent(string $locale): void
    {
        $reference = self::translator()->catalogue(Locales::FALLBACK);
        $catalogue = self::translator()->catalogue($locale);

        foreach ($reference as $key => $value) {
            $expected = self::placeholders($value);
            $actual = self::placeholders($catalogue[$key] ?? '');
            self::assertSame(
                $expected,
                $actual,
                sprintf('Placeholders divergents pour %s en %s', $key, $locale)
            );
        }
    }

    #[DataProvider('locales')]
    public function testNoEmptyTranslation(string $locale): void
    {
        foreach (self::translator()->catalogue($locale) as $key => $value) {
            self::assertNotSame('', trim($value), 'Traduction vide : ' . $key . ' (' . $locale . ')');
        }
    }

    #[DataProvider('locales')]
    public function testTranslationsAreValidUtf8(string $locale): void
    {
        foreach (self::translator()->catalogue($locale) as $key => $value) {
            self::assertTrue(mb_check_encoding($value, 'UTF-8'), 'Encodage invalide : ' . $key);
        }
    }

    public function testAllFirstClassLocalesAreCovered(): void
    {
        self::assertSame(['fr', 'en', 'nl', 'de'], Locales::ALL);
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $value): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $value, $matches);
        $found = $matches[1];
        sort($found);

        return array_values(array_unique($found));
    }
}
