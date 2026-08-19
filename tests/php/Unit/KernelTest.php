<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use SecondStay\I18n\Locales;
use SecondStay\Tests\Support\KernelTestCase;

/**
 * Comportement du noyau sur une installation NON terminée.
 *
 * Le dépôt ne contient jamais `config/local.php` : ces tests décrivent donc
 * l'état d'un déploiement fraîchement copié par FTP.
 */
final class KernelTest extends KernelTestCase
{
    public function testEverythingRedirectsToInstallationUntilItIsFinished(): void
    {
        $response = $this->get('/fr/');

        self::assertSame(302, $response->status());
        self::assertSame('/fr/install', $response->headers()['location'] ?? null);
    }

    public function testInstallationRedirectKeepsTheDetectedLocale(): void
    {
        $response = $this->get('/', ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertSame('/de/install', $response->headers()['location'] ?? null);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function installExpectations(): array
    {
        return [
            ['fr', 'Installation de SecondStay'],
            ['en', 'SecondStay installation'],
            ['nl', 'SecondStay installeren'],
            ['de', 'SecondStay installieren'],
        ];
    }

    #[DataProvider('installExpectations')]
    public function testInstallationWizardIsAvailableInEveryLocale(string $locale, string $expected): void
    {
        $response = $this->get('/' . $locale . '/install');

        self::assertSame(200, $response->status());
        self::assertStringContainsString($expected, $response->content());
        self::assertStringContainsString('<html lang="' . $locale . '"', $response->content());
    }

    public function testInstallationWizardListsRequirements(): void
    {
        $content = $this->get('/fr/install')->content();

        self::assertStringContainsString('data-requirement="php_version"', $content);
        self::assertStringContainsString('data-requirement="ext_pdo_mysql"', $content);
        self::assertStringContainsString('data-requirement="storage_writable"', $content);
    }

    public function testInstallationFormCarriesACsrfToken(): void
    {
        $content = $this->get('/fr/install')->content();

        self::assertMatchesRegularExpression('/name="_csrf" value="[A-Za-z0-9_-]{20,}"/', $content);
    }

    public function testApiVersionRemainsAvailableBeforeInstallation(): void
    {
        $response = $this->get('/api/version');

        self::assertSame(200, $response->status());

        /** @var array{name: string, version: string, locales: list<string>} $payload */
        $payload = json_decode($response->content(), true);
        self::assertSame('SecondStay', $payload['name']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $payload['version']);
        self::assertSame(Locales::ALL, $payload['locales']);
    }

    public function testHealthEndpoint(): void
    {
        $response = $this->get('/api/health');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('"status":"ok"', $response->content());
    }

    public function testUnknownApiRouteReturnsJsonError(): void
    {
        $response = $this->get('/api/unknown');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('"status":404', $response->content());
    }

    /**
     * @return list<array{string}>
     */
    public static function privatePaths(): array
    {
        return [
            ['/src/Core/Kernel.php'],
            ['/config/app.php'],
            ['/config/local.php'],
            ['/vendor/autoload.php'],
            ['/storage/logs/app.log'],
            ['/tests/php/bootstrap.php'],
            ['/composer.json'],
            ['/README.md'],
            ['/.env'],
            ['/VERSION'],
        ];
    }

    #[DataProvider('privatePaths')]
    public function testApplicationRefusesPrivatePathsEvenIfWebServerIsMisconfigured(string $path): void
    {
        self::assertSame(403, $this->get($path)->status());
    }

    public function testSecurityHeadersArePresent(): void
    {
        $headers = $this->get('/fr/install')->headers();

        self::assertSame('nosniff', $headers['x-content-type-options'] ?? null);
        self::assertSame('SAMEORIGIN', $headers['x-frame-options'] ?? null);
        self::assertSame('strict-origin-when-cross-origin', $headers['referrer-policy'] ?? null);
        self::assertArrayHasKey('content-security-policy', $headers);
        self::assertStringContainsString("default-src 'self'", $headers['content-security-policy']);
    }

    public function testErrorPageDoesNotLeakStackTraceInProduction(): void
    {
        $content = $this->get('/api/unknown')->content();

        self::assertStringNotContainsString('#0 ', $content);
        self::assertStringNotContainsString('Kernel.php', $content);
    }

    public function testMutationsWithoutCsrfTokenAreRefused(): void
    {
        $response = $this->kernel()->handle(
            $this->request('/fr/install', 'POST', [], [], ['db_name' => 'x'])
        );

        self::assertSame(403, $response->status());
    }
}
