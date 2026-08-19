<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Http\UrlGuard;

/**
 * SECURITY.md §16 — protection SSRF.
 */
final class UrlGuardTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function blockedUrls(): array
    {
        return [
            ['http://localhost/admin', 'ssrf.private_target'],
            ['http://127.0.0.1:8080/', 'ssrf.private_target'],
            ['http://127.1.2.3/', 'ssrf.private_target'],
            ['http://10.0.0.5/metadata', 'ssrf.private_target'],
            ['http://192.168.1.1/', 'ssrf.private_target'],
            ['http://172.16.0.1/', 'ssrf.private_target'],
            ['http://169.254.169.254/latest/meta-data/', 'ssrf.private_target'],
            ['http://100.64.0.1/', 'ssrf.private_target'],
            ['http://[::1]/', 'ssrf.private_target'],
            ['http://[fd00::1]/', 'ssrf.private_target'],
            ['http://[fe80::1]/', 'ssrf.private_target'],
            ['http://intranet/', 'ssrf.private_target'],
            ['file:///etc/passwd', 'ssrf.scheme_not_allowed'],
            ['gopher://example.test/', 'ssrf.scheme_not_allowed'],
            ['ftp://example.test/', 'ssrf.scheme_not_allowed'],
            ['not-an-url', 'ssrf.invalid_url'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function testBlockedUrls(string $url, string $reason): void
    {
        $result = (new UrlGuard())->inspect($url);

        if ($result['ok'] === true) {
            self::fail($url . ' doit être refusé');
        }

        self::assertSame($reason, $result['reason']);
    }

    public function testPublicIpIsAllowed(): void
    {
        $result = (new UrlGuard())->inspect('https://93.184.216.34/');

        self::assertTrue($result['ok']);
    }

    public function testAllowlistRestrictsHosts(): void
    {
        $guard = new UrlGuard(['example.test']);
        $result = $guard->inspect('https://93.184.216.34/');

        if ($result['ok'] === true) {
            self::fail('Un hôte hors liste blanche doit être refusé.');
        }

        self::assertSame('ssrf.host_not_allowed', $result['reason']);
    }

    public function testAllowlistAcceptsSubdomains(): void
    {
        $guard = new UrlGuard(['github.com']);
        $result = $guard->inspect('https://api.github.com/repos/test/test');

        // La liste blanche accepte les sous-domaines ; le refus éventuel ne
        // peut alors venir que de la cible réseau, pas de l'hôte.
        if ($result['ok'] === false) {
            self::assertNotSame('ssrf.host_not_allowed', $result['reason']);

            return;
        }

        self::assertSame('api.github.com', $result['host']);
    }

    /**
     * @return list<array{string}>
     */
    public static function blockedIps(): array
    {
        return [
            ['127.0.0.1'], ['10.1.2.3'], ['172.20.0.1'], ['192.168.0.1'],
            ['169.254.1.1'], ['0.0.0.0'], ['224.0.0.1'], ['240.0.0.1'],
            ['::1'], ['fc00::1'], ['fe80::abcd'], ['::ffff:127.0.0.1'],
            ['pas-une-ip'],
        ];
    }

    #[DataProvider('blockedIps')]
    public function testBlockedIps(string $ip): void
    {
        self::assertTrue((new UrlGuard())->isBlockedIp($ip), $ip . ' doit être bloqué');
    }

    public function testPublicIpsAreNotBlocked(): void
    {
        self::assertFalse((new UrlGuard())->isBlockedIp('93.184.216.34'));
        self::assertFalse((new UrlGuard())->isBlockedIp('2606:2800:220:1:248:1893:25c8:1946'));
    }
}
