<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use SecondStay\I18n\Locales;
use SecondStay\Tests\Support\KernelTestCase;

final class KernelTest extends KernelTestCase
{
    public function testRootRedirectsToLocalisedHome(): void
    {
        $response = $this->get('/');

        self::assertSame(302, $response->status());
        self::assertSame('/fr', $response->headers()['location'] ?? null);
    }

    public function testRootRespectsAcceptLanguage(): void
    {
        $response = $this->get('/', ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertSame('/de', $response->headers()['location'] ?? null);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function homeExpectations(): array
    {
        return [
            ['fr', 'Votre résidence secondaire, louée simplement'],
            ['en', 'Your holiday home, rented simply'],
            ['nl', 'Uw vakantiewoning, eenvoudig verhuurd'],
            ['de', 'Ihr Ferienhaus, einfach vermietet'],
        ];
    }

    #[DataProvider('homeExpectations')]
    public function testHomeIsRenderedInEveryFirstClassLocale(string $locale, string $expected): void
    {
        $response = $this->get('/' . $locale . '/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString($expected, $response->content());
        self::assertStringContainsString('<html lang="' . $locale . '"', $response->content());
    }

    public function testHomeExposesHreflangForEveryLocale(): void
    {
        $content = $this->get('/fr/')->content();

        foreach (Locales::ALL as $locale) {
            self::assertStringContainsString('hreflang="' . $locale . '"', $content);
        }
        self::assertStringContainsString('hreflang="x-default"', $content);
    }

    public function testLocaleChoiceIsPersistedInFunctionalCookie(): void
    {
        $response = $this->get('/nl/');
        $cookies = $response->cookies();

        self::assertCount(1, $cookies);
        self::assertSame('ss_locale', $cookies[0]['name']);
        self::assertSame('nl', $cookies[0]['value']);
        self::assertSame('Lax', $cookies[0]['options']['samesite'] ?? null);
    }

    public function testExistingCookieIsNotRewritten(): void
    {
        $response = $this->get('/nl/', [], ['ss_locale' => 'nl']);

        self::assertSame([], $response->cookies());
    }

    public function testApiVersionIsPublicAndStructured(): void
    {
        $response = $this->get('/api/version');

        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['content-type']);

        /** @var array{name: string, version: string, locales: list<string>, default_locale: string} $payload */
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

    public function testUnknownRouteRenders404(): void
    {
        $response = $this->get('/fr/inexistant');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Page introuvable', $response->content());
        self::assertStringContainsString('data-error-status="404"', $response->content());
    }

    public function testUnknownRouteIsLocalised(): void
    {
        self::assertStringContainsString('Seite nicht gefunden', $this->get('/de/inexistant')->content());
        self::assertStringContainsString('Pagina niet gevonden', $this->get('/nl/inexistant')->content());
        self::assertStringContainsString('Page not found', $this->get('/en/inexistant')->content());
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
        $headers = $this->get('/fr/')->headers();

        self::assertSame('nosniff', $headers['x-content-type-options'] ?? null);
        self::assertSame('SAMEORIGIN', $headers['x-frame-options'] ?? null);
        self::assertSame('strict-origin-when-cross-origin', $headers['referrer-policy'] ?? null);
        self::assertArrayHasKey('content-security-policy', $headers);
        self::assertStringContainsString("default-src 'self'", $headers['content-security-policy']);
    }

    public function testErrorPageDoesNotLeakStackTraceInProduction(): void
    {
        $content = $this->get('/fr/inexistant')->content();

        self::assertStringNotContainsString('#0 ', $content);
        self::assertStringNotContainsString('Kernel.php', $content);
    }
}
