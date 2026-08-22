<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Logging\LogLevel;
use SecondStay\Logging\Logger;

/**
 * Journal de repli sur disque (SPECIFICATIONS.md §16).
 *
 * Ce fichier n'est écrit que lorsque la base est injoignable — c'est-à-dire
 * exactement quand quelque chose ne va pas et que les lignes affluent. Sans
 * rotation, la panne qu'il documente en produit une seconde : un disque plein
 * sur un hébergement mutualisé à quota, qui emporte aussi les sauvegardes.
 */
final class LoggerFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/secondstay-log-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testWithoutADatabaseTheLineLandsOnDisk(): void
    {
        $this->logger()->error('test', 'panne de base');

        $written = (string) file_get_contents($this->directory . '/app.log');

        self::assertStringContainsString('panne de base', $written);
        self::assertStringContainsString('error', $written);
    }

    /**
     * Le journal ne doit pas transporter de retour à la ligne : une entrée
     * étalée sur plusieurs lignes casse la lecture de toutes les suivantes.
     */
    public function testAMultilineMessageStaysOnOneLine(): void
    {
        $this->logger()->error('test', "première ligne\nseconde ligne\ttabulée");

        $written = (string) file_get_contents($this->directory . '/app.log');

        self::assertSame(1, substr_count($written, "\n"));
        self::assertStringContainsString('première ligne seconde ligne tabulée', $written);
    }

    public function testTheFileIsRotatedOnceItGrowsPastItsLimit(): void
    {
        $file = $this->directory . '/app.log';
        file_put_contents($file, str_repeat('x', Logger::FILE_MAX_BYTES));

        $this->logger()->error('test', 'ligne après rotation');

        self::assertFileExists($file . '.1');
        self::assertSame(Logger::FILE_MAX_BYTES, filesize($file . '.1'));

        // Le fichier courant repart de la ligne qui a déclenché la rotation.
        self::assertStringContainsString('ligne après rotation', (string) file_get_contents($file));
        self::assertLessThan(Logger::FILE_MAX_BYTES, (int) filesize($file));
    }

    public function testAFileBelowTheLimitIsNeverRotated(): void
    {
        $file = $this->directory . '/app.log';
        file_put_contents($file, str_repeat('x', Logger::FILE_MAX_BYTES - 1));

        $this->logger()->error('test', 'ligne ordinaire');

        self::assertFileDoesNotExist($file . '.1');
    }

    /**
     * Les générations se décalent, et la plus ancienne disparaît : le journal
     * de repli est borné, pas cumulatif.
     */
    public function testGenerationsShiftAndTheOldestIsDropped(): void
    {
        $file = $this->directory . '/app.log';

        for ($round = 1; $round <= Logger::FILE_GENERATIONS + 2; $round++) {
            file_put_contents($file, str_repeat('x', Logger::FILE_MAX_BYTES));
            $this->logger()->error('test', 'tour ' . $round);
        }

        for ($generation = 1; $generation <= Logger::FILE_GENERATIONS; $generation++) {
            self::assertFileExists($file . '.' . $generation);
        }

        self::assertFileDoesNotExist($file . '.' . (Logger::FILE_GENERATIONS + 1));
    }

    /**
     * Une génération tournée porte les mêmes lignes, donc les mêmes données
     * personnelles : la rétention doit l'emporter comme les lignes en base.
     */
    public function testRetentionRemovesRotatedFilesPastTheirAge(): void
    {
        $recent = $this->directory . '/app.log.1';
        $old = $this->directory . '/app.log.2';

        file_put_contents($recent, 'récent');
        file_put_contents($old, 'ancien');
        touch($old, time() - 40 * 86400);

        $this->logger()->purgeOlderThan(30);

        self::assertFileExists($recent);
        self::assertFileDoesNotExist($old);
    }

    public function testTheCurrentFileIsNeverRemovedByRetention(): void
    {
        $file = $this->directory . '/app.log';
        file_put_contents($file, 'courant');
        touch($file, time() - 400 * 86400);

        $this->logger()->purgeOlderThan(30);

        self::assertFileExists($file);
    }

    /**
     * Le niveau minimal filtre avant l'écriture : un journal en « warning »
     * ne doit pas payer le coût d'une ligne de débogage.
     */
    public function testAMessageBelowTheMinimumLevelIsNotWritten(): void
    {
        $logger = new Logger($this->directory, LogLevel::Warning);
        $logger->info('test', 'trop bavard');

        self::assertFileDoesNotExist($this->directory . '/app.log');
    }

    private function logger(): Logger
    {
        return new Logger($this->directory, LogLevel::Debug);
    }
}
