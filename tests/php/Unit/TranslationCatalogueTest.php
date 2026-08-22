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

    /**
     * Toute clé écrite littéralement dans le code ou les gabarits existe.
     *
     * La parité entre catalogues ne suffit pas : une clé inventée dans un
     * gabarit est absente des quatre langues à la fois, donc parfaitement
     * symétrique. Elle ne casse rien de visible non plus — le traducteur rend
     * le dernier segment lisible, et « All » s'affiche dans les quatre langues
     * sans que personne s'en aperçoive. C'est exactement le genre de défaut
     * qu'une relecture humaine ne voit pas et qu'une machine voit toujours
     * (TESTING.md §9).
     */
    public function testEveryLiteralKeyUsedInTheProductExists(): void
    {
        $catalogue = self::translator()->catalogue(Locales::FALLBACK);

        $missing = [];
        foreach (self::literalKeys() as $key => $origin) {
            if (!array_key_exists($key, $catalogue)) {
                $missing[] = $key . ' (' . $origin . ')';
            }
        }

        sort($missing);
        self::assertSame([], $missing, 'Clés de traduction inexistantes');
    }

    /**
     * Clés citées littéralement, avec le fichier où elles apparaissent.
     *
     * Les préfixes de concaténation — `t('stay.block.' ~ code)` — se
     * reconnaissent à leur fin : un point ou un tiret bas. Ils sont écartés,
     * faute de quoi ce contrôle ne signalerait que des faux positifs et
     * finirait par être ignoré.
     *
     * @return array<string, string> clé => fichier
     */
    private static function literalKeys(): array
    {
        $root = dirname(__DIR__, 3);
        $keys = [];

        foreach ([$root . '/src', $root . '/templates'] as $directory) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                $found = preg_match_all(
                    "/\\b(?:t|trans|transChoice|flashSuccess|flashError|flashWarning)\\(\\s*'([a-z0-9_.]+)'/i",
                    $contents,
                    $matches
                );

                if ($found === false || $found === 0) {
                    continue;
                }

                foreach ($matches[1] as $key) {
                    if (str_ends_with($key, '.') || str_ends_with($key, '_')) {
                        continue;
                    }

                    $keys[$key] ??= str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        return $keys;
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
