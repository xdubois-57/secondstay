<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SecondStay\I18n\Locales;
use SecondStay\I18n\Translator;
use SecondStay\Pwa\IconGenerator;
use SecondStay\Pwa\ManifestBuilder;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\InMemorySettingsRepository;

/**
 * Application installable : manifeste localisé et icônes générées.
 *
 * Rien de propre au logement n'est figé dans le dépôt : le manifeste et les
 * icônes proviennent des réglages de l'installation.
 */
final class PwaTest extends TestCase
{
    private string $cacheDirectory = '';

    protected function tearDown(): void
    {
        if ($this->cacheDirectory !== '' && is_dir($this->cacheDirectory)) {
            foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->cacheDirectory);
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function manifest(array $values = [], string $basePath = ''): ManifestBuilder
    {
        $settings = new SettingsService(
            new SettingRegistry(),
            new InMemorySettingsRepository(),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        if ($values !== []) {
            $settings->setMany($values);
        }

        return new ManifestBuilder(
            $settings,
            new Translator(dirname(__DIR__, 3) . '/translations'),
            $basePath,
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function locales(): array
    {
        return array_map(static fn (string $locale): array => [$locale], Locales::ALL);
    }

    #[DataProvider('locales')]
    public function testTheManifestIsProducedForEveryLocale(string $locale): void
    {
        $manifest = $this->manifest(['property.name' => 'Maison des Pins'])->build($locale);

        self::assertSame($locale, $manifest['lang']);
        self::assertSame('/' . $locale . '/', $manifest['start_url']);
        self::assertSame('/', $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('Maison des Pins', $manifest['name']);

        // La description et les raccourcis suivent la langue demandée.
        self::assertIsString($manifest['description']);
        self::assertStringContainsString('Maison des Pins', $manifest['description']);
        self::assertStringNotContainsString('{property}', $manifest['description']);

        /** @var list<array{name: string, url: string}> $shortcuts */
        $shortcuts = $manifest['shortcuts'];
        self::assertSame('/' . $locale . '/account', $shortcuts[0]['url']);
    }

    public function testTranslatedManifestsDifferBetweenLocales(): void
    {
        $builder = $this->manifest(['property.name' => 'Maison des Pins']);

        $descriptions = [];
        foreach (Locales::ALL as $locale) {
            $descriptions[] = $builder->build($locale)['description'];
        }

        self::assertCount(count(Locales::ALL), array_unique($descriptions));
    }

    public function testAnUnknownLocaleFallsBackRatherThanProducingAnInvalidManifest(): void
    {
        $manifest = $this->manifest()->build('es');

        self::assertSame(Locales::FALLBACK, $manifest['lang']);
        self::assertSame('SecondStay', $manifest['name']);
    }

    public function testEveryUrlCarriesTheBasePathOfASubdirectoryInstallation(): void
    {
        $manifest = $this->manifest(['property.name' => 'Maison des Pins'], '/sejour')->build('fr');

        self::assertSame('/sejour/fr/', $manifest['start_url']);
        self::assertSame('/sejour/', $manifest['scope']);

        /** @var list<array{src: string}> $icons */
        $icons = $manifest['icons'];
        foreach ($icons as $icon) {
            self::assertStringStartsWith('/sejour/icon-', $icon['src']);
        }
    }

    public function testIconsCoverBothPurposesAndSizes(): void
    {
        /** @var list<array{src: string, sizes: string, type: string, purpose: string}> $icons */
        $icons = $this->manifest()->build('fr')['icons'];

        self::assertCount(count(ManifestBuilder::ICON_SIZES) * 2, $icons);

        $seen = [];
        foreach ($icons as $icon) {
            self::assertSame('image/png', $icon['type']);
            $seen[] = $icon['sizes'] . ':' . $icon['purpose'];
        }

        self::assertContains('192x192:any', $seen);
        self::assertContains('192x192:maskable', $seen);
        self::assertContains('512x512:any', $seen);
        self::assertContains('512x512:maskable', $seen);
    }

    public function testTheShortNameStaysUsableOnAHomeScreen(): void
    {
        $manifest = $this->manifest(['property.name' => 'Maison des Pins de la Côte Sauvage'])->build('fr');

        self::assertSame(12, mb_strlen((string) $manifest['short_name']));
    }

    public function testTheManifestIsValidJson(): void
    {
        $json = $this->manifest(['property.name' => 'Maison « des Pins »'])->toJson('de');

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('Maison « des Pins »', $decoded['name']);
        // Les barres obliques des URL restent lisibles.
        self::assertStringContainsString('"start_url": "/de/"', $json);
    }

    // --- Icônes ------------------------------------------------------------

    /**
     * @return list<array{string, string}>
     */
    public static function labels(): array
    {
        return [
            ['Maison des Pins', 'MD'],
            ['maison', 'M'],
            ['Été Indien', 'EI'],
            ['  ', 'SS'],
            ['', 'SS'],
            ['123 Rue', '1R'],
        ];
    }

    #[DataProvider('labels')]
    public function testInitialsStayAsciiAndShort(string $label, string $expected): void
    {
        self::assertSame($expected, IconGenerator::initials($label));
    }

    public function testIconsAreGeneratedAtTheRequestedSize(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Extension GD requise.');
        }

        $this->cacheDirectory = sys_get_temp_dir() . '/secondstay-icons-' . bin2hex(random_bytes(6));
        $generator = new IconGenerator($this->cacheDirectory);

        foreach (IconGenerator::ALLOWED_SIZES as $size) {
            foreach ([false, true] as $maskable) {
                $file = $generator->icon('Maison des Pins', $size, $maskable);

                self::assertFileExists($file);
                $info = getimagesize($file);
                self::assertIsArray($info);
                self::assertSame($size, $info[0]);
                self::assertSame($size, $info[1]);
                self::assertSame('image/png', $info['mime']);
            }
        }
    }

    public function testAGeneratedIconIsReusedFromTheCache(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Extension GD requise.');
        }

        $this->cacheDirectory = sys_get_temp_dir() . '/secondstay-icons-' . bin2hex(random_bytes(6));
        $generator = new IconGenerator($this->cacheDirectory);

        $first = $generator->icon('Maison des Pins', 192);
        $stamp = filemtime($first);
        $second = $generator->icon('Maison des Pins', 192);

        self::assertSame($first, $second);
        self::assertSame($stamp, filemtime($second));

        // Un autre logement produit une icône distincte.
        self::assertNotSame($first, $generator->icon('Chalet du Lac', 192));
    }

    public function testAnUnsupportedSizeIsRefused(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/secondstay-icons-' . bin2hex(random_bytes(6));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pwa.error.unsupported_size');

        (new IconGenerator($this->cacheDirectory))->icon('Maison', 1024);
    }
}
