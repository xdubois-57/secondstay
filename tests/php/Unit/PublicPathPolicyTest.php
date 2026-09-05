<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Core\PublicPathPolicy;

final class PublicPathPolicyTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function blockedPaths(): array
    {
        return [
            ['/src/Core/Kernel.php'],
            ['/config/app.php'],
            ['/config/local.php'],
            ['/vendor/autoload.php'],
            ['/storage/logs/app.log'],
            ['/storage/backups/backup.zip'],
            ['/tests/php/bootstrap.php'],
            ['/migrations/001_init.sql'],
            ['/scripts/check.sh'],
            ['/translations/fr/common.php'],
            ['/templates/layout/base.html.twig'],
            ['/.github/workflows/ci.yml'],
            ['/.github/workflows/checks.yml'],
            ['/.git/config'],
            ['/.env'],
            ['/.env.local'],
            ['/composer.json'],
            ['/composer.lock'],
            ['/package.json'],
            ['/package-lock.json'],
            ['/phpunit.xml.dist'],
            ['/phpstan.neon.dist'],
            ['/README.md'],
            ['/AGENTS.md'],
            ['/SECURITY.md'],
            ['/VERSION'],
            ['/LICENSE'],
            ['/node_modules/vitest/package.json'],
            ['/coverage/lcov.info'],
            ['/public/../config/app.php'],
            ['/public/src/Core/Kernel.php'],
            ['/SRC/Core/Kernel.php'],
            ['/dump.sql'],
            ['/backup.zip'],
            ['/deploy.sh'],
            ['/private.pem'],
        ];
    }

    #[DataProvider('blockedPaths')]
    public function testBlockedPathsAreRefused(string $path): void
    {
        self::assertTrue(PublicPathPolicy::isBlocked($path), $path . ' doit être refusé');
    }

    /**
     * @return list<array{string}>
     */
    public static function publicPaths(): array
    {
        return [
            ['/'],
            ['/fr/'],
            ['/en/'],
            ['/nl/'],
            ['/de/'],
            ['/api/version'],
            ['/api/health'],
            ['/assets/css/app.css'],
            ['/assets/js/app.js'],
            ['/assets/vendor/bootstrap/css/bootstrap.min.css'],
            ['/.well-known/security.txt'],
            ['/favicon.ico'],
        ];
    }

    #[DataProvider('publicPaths')]
    public function testPublicPathsAreAllowed(string $path): void
    {
        self::assertFalse(PublicPathPolicy::isBlocked($path), $path . ' doit rester accessible');
    }

    public function testUrlEncodedTraversalIsBlocked(): void
    {
        self::assertTrue(PublicPathPolicy::isBlocked('/%2e%2e/config/app.php'));
        self::assertTrue(PublicPathPolicy::isBlocked('/assets/..%2fconfig/app.php'));
    }

    public function testHtaccessCoversEveryBlockedDirectory(): void
    {
        $raw = file_get_contents(dirname(__DIR__, 3) . '/.htaccess');
        self::assertIsString($raw);

        // Les règles Apache échappent les points : on compare sur le texte
        // débarrassé des antislashs d'échappement.
        $htaccess = str_replace('\\', '', $raw);

        foreach (PublicPathPolicy::BLOCKED_DIRECTORIES as $directory) {
            self::assertStringContainsString(
                ltrim($directory, '.'),
                $htaccess,
                'Le .htaccess racine doit refuser ' . $directory
            );
        }

        foreach (PublicPathPolicy::BLOCKED_EXTENSIONS as $extension) {
            if ($extension === 'md') {
                continue; // couvert par la règle documentaire dédiée
            }
            self::assertStringContainsString($extension, $htaccess, 'Extension non couverte : ' . $extension);
        }

        self::assertStringContainsString('Require all denied', $htaccess);
    }
}
