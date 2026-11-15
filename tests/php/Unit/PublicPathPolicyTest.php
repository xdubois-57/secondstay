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

    /**
     * `bootstrap/` n'est jamais dans l'artefact, mais une installation faite
     * par clone met tout le dépôt sous la racine web : l'installeur y serait
     * alors joignable. Il refuse de tourner sur une instance installée, mais
     * un garde-fou applicatif n'est pas une raison de le laisser atteignable.
     *
     * `token.php`, lui, porte le jeton de l'assistant (SECURITY.md §41) :
     * il est lu comme du texte par l'application et ne doit jamais être servi.
     */
    public function testTheInstallerAndItsTokenAreNeverServed(): void
    {
        self::assertTrue(PublicPathPolicy::isBlocked('/bootstrap/bootstrap.php'));
        self::assertTrue(PublicPathPolicy::isBlocked('/public/bootstrap/bootstrap.php'));
        self::assertTrue(PublicPathPolicy::isBlocked('/token.php'));
        self::assertTrue(PublicPathPolicy::isBlocked('/TOKEN.PHP'));

        // Déposé à la racine, l'installeur lui-même n'est pas un chemin privé :
        // c'est toute sa raison d'être. Il se supprime une fois son travail
        // fait, et refuse de tourner tant qu'une installation existe.
        self::assertFalse(PublicPathPolicy::isBlocked('/bootstrap.php'));
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
    /**
     * « Pas bloqué » ne suffisait pas : sous Apache, la règle de rattrapage
     * envoyait `/bootstrap.php` au contrôleur frontal. L'étape qui copie
     * l'archive pose ce `.htaccess` ; dès lors, chaque étape suivante de
     * l'installeur recevait la page de l'assistant au lieu de s'exécuter, et
     * l'installation échouait sur « La réponse du serveur n'a pas pu être
     * interprétée ». Le serveur PHP intégré de la CI ignore `.htaccess` : seul
     * ce test, et un essai sous Apache, pouvaient le voir.
     */
    public function testHtaccessLetsTheInstallerRunBeforeTheFrontController(): void
    {
        $lines = file(dirname(__DIR__, 3) . '/.htaccess', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $lines = array_map('trim', $lines);

        $installer = array_search('RewriteRule ^bootstrap\.php$ - [L]', $lines, true);
        $frontController = array_search('RewriteRule ^ public/index.php [L]', $lines, true);
        $firstDenial = null;
        foreach ($lines as $number => $line) {
            if (str_starts_with($line, 'RewriteRule') && str_contains($line, '[F,')) {
                $firstDenial = $number;
                break;
            }
        }

        self::assertIsInt($installer, 'Le .htaccess doit laisser s’exécuter bootstrap.php.');
        self::assertIsInt($frontController);
        self::assertIsInt($firstDenial);
        self::assertLessThan($frontController, $installer, 'L’exception doit précéder le contrôleur frontal.');
        self::assertGreaterThan($firstDenial, $installer, 'Les refus doivent passer avant l’exception.');
        // L'exception ne vaut que pour le fichier réellement présent.
        self::assertSame('RewriteCond %{REQUEST_FILENAME} -f', $lines[$installer - 1]);
    }
}
