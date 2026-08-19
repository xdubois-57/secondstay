<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Core\Http\Request;
use SecondStay\I18n\LocaleResolver;

final class LocaleResolverTest extends TestCase
{
    /**
     * @param array<string, string> $cookies
     */
    private function request(string $acceptLanguage = '', array $cookies = []): Request
    {
        return new Request(
            'GET',
            '/',
            [],
            [],
            $acceptLanguage === '' ? [] : ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage],
            $cookies,
        );
    }

    public function testUrlPrefixWins(): void
    {
        $resolver = new LocaleResolver('fr');
        self::assertSame('de', $resolver->resolve($this->request('nl'), 'de'));
    }

    public function testAccountPreferenceBeatsCookie(): void
    {
        $resolver = new LocaleResolver('fr');
        self::assertSame('en', $resolver->resolve($this->request('', ['ss_locale' => 'nl']), null, 'en'));
    }

    public function testCookieBeatsAcceptLanguage(): void
    {
        $resolver = new LocaleResolver('fr');
        self::assertSame('nl', $resolver->resolve($this->request('de', ['ss_locale' => 'nl'])));
    }

    public function testAcceptLanguageIsUsedWithQuality(): void
    {
        $resolver = new LocaleResolver('fr');
        self::assertSame('de', $resolver->resolve($this->request('es;q=0.9, de;q=0.8, fr;q=0.1')));
    }

    public function testFallsBackToInstallationDefault(): void
    {
        $resolver = new LocaleResolver('nl');
        self::assertSame('nl', $resolver->resolve($this->request('es-ES,es;q=0.9')));
    }

    public function testUltimateFallbackIsFrench(): void
    {
        $resolver = new LocaleResolver('zz');
        self::assertSame('fr', $resolver->resolve($this->request()));
    }

    public function testUnsupportedCookieIsIgnored(): void
    {
        $resolver = new LocaleResolver('fr');
        self::assertSame('en', $resolver->resolve($this->request('en-GB', ['ss_locale' => 'es'])));
    }

    public function testExtractPrefix(): void
    {
        $resolver = new LocaleResolver();

        self::assertSame(['locale' => 'fr', 'path' => '/'], $resolver->extractPrefix('/fr/'));
        self::assertSame(['locale' => 'de', 'path' => '/tarifs'], $resolver->extractPrefix('/de/tarifs'));
        self::assertSame(['locale' => null, 'path' => '/api/version'], $resolver->extractPrefix('/api/version'));
        self::assertSame(['locale' => null, 'path' => '/'], $resolver->extractPrefix('/'));
        self::assertSame(['locale' => 'nl', 'path' => '/a/b'], $resolver->extractPrefix('/nl/a/b/'));
    }

    public function testFromAcceptLanguageIgnoresWildcard(): void
    {
        $resolver = new LocaleResolver();
        self::assertNull($resolver->fromAcceptLanguage('*'));
        self::assertNull($resolver->fromAcceptLanguage(''));
        self::assertSame('nl', $resolver->fromAcceptLanguage('nl-BE,nl;q=0.9,en;q=0.5'));
    }
}
