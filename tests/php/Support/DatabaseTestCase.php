<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use PHPUnit\Framework\TestCase;
use SecondStay\Core\Paths;
use SecondStay\Database\Database;
use SecondStay\Database\DatabaseConfig;
use SecondStay\Database\Migrator;

/**
 * Base des tests d'intégration base de données.
 *
 * La base de test est explicitement configurée par variables d'environnement
 * (TESTING.md §5) : jamais de base de production, jamais de valeur par défaut
 * pointant vers une installation réelle.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $database;

    protected Paths $paths;

    protected string $storagePath;

    protected function setUp(): void
    {
        $config = self::databaseConfig();
        if ($config === null) {
            self::markTestSkipped('Base de test non configurée (SECONDSTAY_TEST_DB_NAME).');
        }

        $this->database = new Database($config);
        if (!$this->database->isReachable()) {
            self::markTestSkipped('Base de test injoignable.');
        }

        $this->storagePath = sys_get_temp_dir() . '/secondstay-test-' . bin2hex(random_bytes(6));
        $this->paths = new Paths(self::projectRoot(), $this->storagePath);
        $this->paths->ensureStorageDirectories();

        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        // `setUp()` peut avoir marqué le test ignoré avant d'ouvrir un bac à
        // sable : le nettoyage ne doit pas masquer la raison réelle.
        if (isset($this->storagePath)) {
            self::removeDirectory($this->storagePath);
        }
    }

    protected function resetSchema(): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->database->tables() as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $this->migrator()->migrate();
    }

    protected function migrator(): Migrator
    {
        return new Migrator($this->database, self::projectRoot() . '/migrations');
    }

    public static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function databaseConfig(): ?DatabaseConfig
    {
        $name = getenv('SECONDSTAY_TEST_DB_NAME');
        if ($name === false || $name === '') {
            return null;
        }

        return new DatabaseConfig(
            (string) (getenv('SECONDSTAY_TEST_DB_HOST') ?: '127.0.0.1'),
            (int) (getenv('SECONDSTAY_TEST_DB_PORT') ?: '3306'),
            $name,
            (string) (getenv('SECONDSTAY_TEST_DB_USER') ?: 'root'),
            (string) (getenv('SECONDSTAY_TEST_DB_PASSWORD') ?: ''),
        );
    }

    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
