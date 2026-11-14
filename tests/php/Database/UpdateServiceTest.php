<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Backup\BackupService;
use SecondStay\Core\Paths;
use SecondStay\Logging\Logger;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Release\ReleaseArtifactBuilder;
use SecondStay\Tests\Support\DatabaseTestCase;
use SecondStay\Update\FakeReleaseProvider;
use SecondStay\Update\ReleaseInfo;
use SecondStay\Update\UpdateService;
use ZipArchive;

/**
 * Le flux de mise à jour est testé de bout en bout avec un fournisseur de
 * release factice : aucun accès réseau n'est nécessaire (TESTING.md §8).
 */
final class UpdateServiceTest extends DatabaseTestCase
{
    private string $sandboxRoot;

    private Paths $sandboxPaths;

    private FakeReleaseProvider $provider;

    private UpdateService $updates;

    private MaintenanceMode $maintenance;

    private string $artifactDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandboxRoot = sys_get_temp_dir() . '/secondstay-update-' . bin2hex(random_bytes(6));
        $this->artifactDirectory = $this->sandboxRoot . '-artifacts';
        mkdir($this->sandboxRoot . '/public', 0o750, true);
        mkdir($this->sandboxRoot . '/vendor', 0o750, true);
        mkdir($this->sandboxRoot . '/migrations', 0o750, true);
        mkdir($this->artifactDirectory, 0o750, true);

        file_put_contents($this->sandboxRoot . '/VERSION', "1.0.0\n");
        file_put_contents($this->sandboxRoot . '/public/index.php', "<?php // ancienne version\n");
        file_put_contents($this->sandboxRoot . '/vendor/autoload.php', "<?php // ancien autoload\n");
        foreach (glob(self::projectRoot() . '/migrations/*.sql') ?: [] as $migration) {
            copy($migration, $this->sandboxRoot . '/migrations/' . basename($migration));
        }

        $this->sandboxPaths = new Paths($this->sandboxRoot, $this->sandboxRoot . '/storage');
        $this->sandboxPaths->ensureStorageDirectories();

        $this->maintenance = new MaintenanceMode($this->sandboxPaths->storage('maintenance.json'));
        $this->provider = new FakeReleaseProvider();

        $this->updates = new UpdateService(
            $this->provider,
            $this->sandboxPaths,
            $this->database,
            new BackupService($this->database, $this->sandboxPaths, $this->maintenance, '1.0.0'),
            $this->maintenance,
            new Logger($this->sandboxPaths->storage('logs')),
            new AuditTrail($this->database),
        );
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->sandboxRoot);
        self::removeDirectory($this->artifactDirectory);
        parent::tearDown();
    }

    /**
     * Construit un artefact réel puis ajuste sa version : l'artefact reste
     * conforme à la politique de release.
     *
     * @param array<string, string> $extraEntries
     */
    private function buildArtifact(string $version, array $extraEntries = []): string
    {
        $staging = $this->artifactDirectory . '/staging-' . $version;
        $path = $this->artifactDirectory . '/secondstay-' . $version . '.zip';

        (new ReleaseArtifactBuilder(self::projectRoot(), $staging))->build($path);
        ReleaseArtifactBuilder::removeDirectory($staging);

        $zip = new ZipArchive();
        $zip->open($path);
        $zip->addFromString('VERSION', $version . "\n");
        $zip->addFromString('public/index.php', "<?php // version " . $version . "\n");
        foreach ($extraEntries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function latestRelease(bool $allowPrerelease = false): ReleaseInfo
    {
        $latest = $this->updates->check($allowPrerelease)['latest'];
        self::assertNotNull($latest);

        return $latest;
    }

    /**
     * Release dont l'empreinte annoncée est celle de l'archive : la situation
     * normale. `$digest` permet d'en annoncer une autre, ou aucune.
     */
    private function release(string $version, string $artifact, ?string $digest = null): ReleaseInfo
    {
        return new ReleaseInfo(
            $version,
            'v' . $version,
            'secondstay-' . $version . '.zip',
            'https://example.test/secondstay-' . $version . '.zip',
            1024,
            gmdate('c'),
            'Notes de version ' . $version,
            false,
            $digest ?? (string) hash_file('sha256', $artifact),
        );
    }

    /**
     * @param array<string, string> $extraEntries
     */
    private function offer(string $version, array $extraEntries = [], ?string $digest = null): void
    {
        $artifact = $this->buildArtifact($version, $extraEntries);
        $this->provider->addRelease($this->release($version, $artifact, $digest), $artifact);
    }

    public function testCheckDetectsANewerRelease(): void
    {
        $this->offer('1.1.0');

        $state = $this->updates->check();

        self::assertTrue($state['available']);
        self::assertSame('1.0.0', $state['current']);
        self::assertSame('1.1.0', $state['latest']?->version);
    }

    public function testCheckIgnoresOlderOrEqualReleases(): void
    {
        $this->offer('1.0.0');

        self::assertFalse($this->updates->check()['available']);
    }

    public function testPrereleasesRequireAnExplicitChannel(): void
    {
        $prerelease = new ReleaseInfo('2.0.0', 'v2.0.0', 'secondstay-2.0.0.zip', 'https://example.test/a.zip', 1, gmdate('c'), '', true);
        $this->provider->addRelease($prerelease, $this->buildArtifact('2.0.0'));

        self::assertFalse($this->updates->check(false)['available']);
        self::assertTrue($this->updates->check(true)['available']);
    }

    public function testInstallReplacesCodeAndVersion(): void
    {
        $this->offer('1.1.0');
        $result = $this->updates->install($this->latestRelease(), 'owner@example.test', 1);

        self::assertTrue($result['installed']);
        self::assertFalse($result['rolled_back']);
        self::assertSame('1.1.0', trim((string) file_get_contents($this->sandboxRoot . '/VERSION')));
        self::assertStringContainsString('version 1.1.0', (string) file_get_contents($this->sandboxRoot . '/public/index.php'));
        self::assertFileExists($this->sandboxRoot . '/vendor/autoload.php');
        self::assertFileExists($this->sandboxRoot . '/templates/layout/base.html.twig');
    }

    public function testInstallCreatesABackupFirst(): void
    {
        $this->offer('1.1.0');
        $result = $this->updates->install($this->latestRelease());

        self::assertNotNull($result['backup']);
        self::assertFileExists((string) $result['backup']);
    }

    public function testAnArtifactCarryingRuntimeDataIsRefusedBeforeInstall(): void
    {
        $this->offer('1.1.0', [
            'storage/documents/facture.txt' => 'contenu malveillant',
            'config/local.php' => "<?php return ['keep' => false];\n",
        ]);

        $problems = $this->updates->validateArtifact(
            $this->artifactDirectory . '/secondstay-1.1.0.zip'
        );

        self::assertNotSame([], $problems);
        self::assertStringContainsString('storage/documents/facture.txt', implode(' ', $problems));
        self::assertStringContainsString('config/local.php', implode(' ', $problems));
    }

    public function testInstallLeavesRuntimeDataAndLocalConfigUntouched(): void
    {
        file_put_contents($this->sandboxPaths->storage('documents/facture.txt'), 'donnée persistante');
        mkdir($this->sandboxRoot . '/config', 0o750, true);
        file_put_contents($this->sandboxRoot . '/config/local.php', "<?php return ['keep' => true];\n");

        $this->offer('1.1.0');
        $result = $this->updates->install($this->latestRelease());

        self::assertTrue($result['installed']);
        self::assertSame('donnée persistante', file_get_contents($this->sandboxPaths->storage('documents/facture.txt')));
        self::assertStringContainsString("'keep' => true", (string) file_get_contents($this->sandboxRoot . '/config/local.php'));
    }

    public function testFailedMigrationTriggersRollback(): void
    {
        $this->offer('1.2.0', [
            'migrations/9999_broken.sql' => "CREATE TABLE `broken` (this is not valid sql);\n",
        ]);

        $result = $this->updates->install($this->latestRelease(), 'owner@example.test', 1);

        self::assertFalse($result['installed']);
        self::assertTrue($result['rolled_back']);
        self::assertSame('1.0.0', trim((string) file_get_contents($this->sandboxRoot . '/VERSION')));
        self::assertStringContainsString('ancienne version', (string) file_get_contents($this->sandboxRoot . '/public/index.php'));
        self::assertFileDoesNotExist($this->sandboxRoot . '/migrations/9999_broken.sql');

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');
        self::assertContains('update.rolled_back', $actions);
    }

    public function testMaintenanceIsLiftedAfterInstall(): void
    {
        $this->offer('1.1.0');
        $this->updates->install($this->latestRelease());

        self::assertFalse($this->maintenance->isActive());
    }

    public function testArtifactValidationRejectsAForeignArchive(): void
    {
        $path = $this->artifactDirectory . '/not-secondstay.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'contenu arbitraire');
        $zip->close();

        $problems = $this->updates->validateArtifact($path);
        self::assertNotSame([], $problems);
        self::assertSame('update.error.asset_no_version', $problems[0]);
    }

    public function testArtifactValidationRejectsAnArchiveContainingTests(): void
    {
        $path = $this->buildArtifact('1.3.0', ['tests/php/bootstrap.php' => '<?php']);

        $problems = $this->updates->validateArtifact($path);

        self::assertNotSame([], $problems);
        self::assertStringContainsString('update.error.artifact', $problems[0]);
    }

    public function testMissingAssetIsReported(): void
    {
        self::assertSame(['update.error.asset_missing'], $this->updates->validateArtifact('/nonexistent.zip'));
    }

    public function testInstallIsAudited(): void
    {
        $this->offer('1.1.0');
        $this->updates->install($this->latestRelease(), 'owner@example.test', 1);

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');
        self::assertContains('update.installed', $actions);
    }

    public function testHealthCheckFailsWhenMigrationsArePending(): void
    {
        self::assertTrue($this->updates->healthCheck());

        file_put_contents($this->sandboxRoot . '/migrations/9999_pending.sql', "SELECT 1;\n");

        self::assertFalse($this->updates->healthCheck());
    }
    // --- Empreinte -------------------------------------------------------

    public function testAReleaseWithoutDigestIsRefusedAndNothingChanges(): void
    {
        $this->offer('1.1.0', [], '');

        $this->expectExceptionMessage('update.error.digest_missing');

        try {
            $this->updates->install($this->latestRelease());
        } finally {
            self::assertSame('1.0.0', $this->updates->currentVersion());
            self::assertStringContainsString('ancienne version', (string) file_get_contents($this->sandboxRoot . '/public/index.php'));
            self::assertFalse($this->maintenance->isActive());
        }
    }

    public function testAnArchiveThatDoesNotMatchItsDigestIsRefused(): void
    {
        $this->offer('1.1.0', [], str_repeat('0', 64));

        $this->expectExceptionMessage('update.error.digest_mismatch');

        try {
            $this->updates->install($this->latestRelease());
        } finally {
            self::assertSame('1.0.0', $this->updates->currentVersion());
            self::assertSame([], glob($this->sandboxPaths->storage('backups') . '/*.zip') ?: []);
        }
    }

    // --- Permutation -----------------------------------------------------

    /**
     * Écraser fichier par fichier laissait en place tout ce que la nouvelle
     * version avait supprimé : une classe retirée restait chargeable, une
     * migration renommée restait appliquable.
     */
    public function testAFileRemovedByTheNewVersionDisappears(): void
    {
        mkdir($this->sandboxRoot . '/src', 0o750, true);
        file_put_contents($this->sandboxRoot . '/src/Obsolete.php', "<?php // retiré en 1.1.0\n");

        $this->offer('1.1.0');
        $result = $this->updates->install($this->latestRelease());

        self::assertTrue($result['installed']);
        self::assertFileDoesNotExist($this->sandboxRoot . '/src/Obsolete.php');
        self::assertFileExists($this->sandboxRoot . '/src/Update/UpdateService.php');
    }

    public function testNoWorkingFileIsLeftBehind(): void
    {
        $this->offer('1.1.0');
        $this->updates->install($this->latestRelease());

        self::assertSame([], glob($this->sandboxPaths->storage('temp') . '/update-*') ?: []);
        self::assertFalse($this->updates->hasInterruptedUpdate());
    }

    // --- Retour arrière de la base -----------------------------------------

    /**
     * Un retour arrière qui ne rendait que les fichiers laissait l'ancien
     * code sur le nouveau schéma. La table créée par la première migration
     * doit disparaître avec elle, et la migration ne plus être notée comme
     * appliquée — sinon la tentative suivante l'ignorerait.
     */
    public function testAFailedMigrationAlsoRestoresTheDatabase(): void
    {
        $this->offer('1.2.0', [
            'migrations/9998_probe.sql' => "CREATE TABLE `update_probe` (`id` INT NOT NULL PRIMARY KEY);\n",
            'migrations/9999_broken.sql' => "CREATE TABLE `broken` (this is not valid sql);\n",
        ]);

        $result = $this->updates->install($this->latestRelease());

        self::assertTrue($result['rolled_back']);
        self::assertFalse($this->database->tableExists('update_probe'));
        $applied = (new \SecondStay\Database\Migrator($this->database, $this->sandboxPaths->migrations()))->applied();
        self::assertArrayNotHasKey('9998', $applied);
        self::assertTrue($this->updates->healthCheck());
        self::assertFalse($this->maintenance->isActive());
    }

    // --- Reprise d'une mise à jour interrompue -----------------------------

    /**
     * Reproduit l'état exact que laisse un processus tué pendant la
     * permutation : ancienne version de côté, nouvelle en place, site fermé,
     * journal présent.
     */
    public function testAnUpdateKilledDuringTheSwapIsUndone(): void
    {
        $work = $this->sandboxPaths->storage('temp/update-killed');
        mkdir($work . '/rollback', 0o750, true);
        rename($this->sandboxRoot . '/public', $work . '/rollback/public');
        mkdir($this->sandboxRoot . '/public', 0o750);
        file_put_contents($this->sandboxRoot . '/public/index.php', "<?php // version à moitié posée\n");
        file_put_contents($this->sandboxRoot . '/VERSION', "1.1.0\n");
        $this->maintenance->enable('maintenance.reason.update');
        $this->writeJournal([
            'from' => '1.0.0',
            'to' => '1.1.0',
            'work_directory' => $work,
            'rollback_directory' => $work . '/rollback',
            'backup' => '',
            'tables_before' => $this->database->tables(),
            'maintenance_was_active' => false,
            'phase' => 'swapping',
            'started_at' => time(),
        ]);

        self::assertTrue($this->updates->hasInterruptedUpdate());
        self::assertTrue($this->updates->recoverInterrupted());

        self::assertSame('1.0.0', $this->updates->currentVersion());
        self::assertStringContainsString('ancienne version', (string) file_get_contents($this->sandboxRoot . '/public/index.php'));
        self::assertFalse($this->maintenance->isActive());
        self::assertFalse($this->updates->hasInterruptedUpdate());
        self::assertDirectoryDoesNotExist($work);

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');
        self::assertContains('update.recovered', $actions);
    }

    public function testAnUpdateKilledDuringMigrationsRestoresTheDatabase(): void
    {
        $backup = (new BackupService($this->database, $this->sandboxPaths, $this->maintenance, '1.0.0'))->create(false);
        $tablesBefore = $this->database->tables();
        $this->database->execute('CREATE TABLE `update_probe` (`id` INT NOT NULL PRIMARY KEY)');

        $work = $this->sandboxPaths->storage('temp/update-killed');
        mkdir($work . '/rollback', 0o750, true);
        $this->maintenance->enable('maintenance.reason.update');
        $this->writeJournal([
            'from' => '1.0.0',
            'to' => '1.1.0',
            'work_directory' => $work,
            'rollback_directory' => $work . '/rollback',
            'backup' => $backup['path'],
            'tables_before' => $tablesBefore,
            'maintenance_was_active' => false,
            'phase' => 'migrating',
            'started_at' => time(),
        ]);

        self::assertTrue($this->updates->recoverInterrupted());
        self::assertFalse($this->database->tableExists('update_probe'));
        self::assertFalse($this->maintenance->isActive());
    }

    /**
     * Tant que le processus qui met à jour est vivant, il tient le verrou :
     * personne ne défait son travail sous ses pieds, ni n'en lance un second.
     */
    public function testARunningUpdateIsNeitherRecoveredNorDoubled(): void
    {
        $this->writeJournal(['from' => '1.0.0', 'to' => '1.1.0', 'phase' => 'swapping', 'started_at' => time()]);
        $handle = fopen($this->sandboxPaths->storage('update/update.lock'), 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX));

        try {
            self::assertFalse($this->updates->recoverInterrupted());
            self::assertTrue($this->updates->hasInterruptedUpdate());

            $this->offer('1.1.0');
            $this->expectExceptionMessage('déjà en cours');
            $this->updates->install($this->latestRelease());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testAnUnreadableJournalIsDiscarded(): void
    {
        mkdir($this->sandboxPaths->storage('update'), 0o750, true);
        file_put_contents($this->sandboxPaths->storage('update') . '/journal.json', '{pas du json');
        self::assertTrue($this->updates->hasInterruptedUpdate());

        self::assertFalse($this->updates->recoverInterrupted());
        self::assertFalse($this->updates->hasInterruptedUpdate());
    }

    // --- Vérification mémorisée ------------------------------------------

    public function testACheckIsRememberedUntilItExpires(): void
    {
        $now = 1_800_000_000;
        $updates = $this->updatesAt(static function () use (&$now): int {
            return $now;
        });
        $this->offer('1.1.0');

        $updates->refresh();
        $cached = $updates->cachedState();
        self::assertNotNull($cached);
        self::assertSame('1.1.0', $cached['latest']?->version);
        self::assertTrue($cached['available']);

        // Un autre canal n'est pas la même question.
        self::assertNull($updates->cachedState(true));

        $now += UpdateService::CHECK_TTL_SECONDS + 1;
        self::assertNull($updates->cachedState());
    }

    public function testTheRememberedCheckIsForgottenOnceTheVersionChanges(): void
    {
        $this->offer('1.1.0');
        $this->updates->refresh();
        self::assertNotNull($this->updates->cachedState());

        file_put_contents($this->sandboxRoot . '/VERSION', "1.1.0\n");

        self::assertNull($this->updates->cachedState());
    }

    public function testStateOnlyCallsTheSourceWhenNothingValidIsRemembered(): void
    {
        $this->offer('1.1.0');

        $first = $this->updates->state();
        $this->offer('1.2.0');
        $second = $this->updates->state();

        self::assertSame('1.1.0', $first['latest']?->version);
        self::assertSame('1.1.0', $second['latest']?->version);
        self::assertSame('1.2.0', $this->updates->refresh()['latest']?->version);
    }

    /**
     * @param array<string, mixed> $journal
     */
    private function writeJournal(array $journal): void
    {
        $directory = $this->sandboxPaths->storage('update');
        if (!is_dir($directory)) {
            mkdir($directory, 0o750, true);
        }
        file_put_contents($directory . '/journal.json', json_encode($journal, JSON_THROW_ON_ERROR));
    }

    private function updatesAt(callable $clock): UpdateService
    {
        return new UpdateService(
            $this->provider,
            $this->sandboxPaths,
            $this->database,
            new BackupService($this->database, $this->sandboxPaths, $this->maintenance, '1.0.0'),
            $this->maintenance,
            new Logger($this->sandboxPaths->storage('logs')),
            new AuditTrail($this->database),
            $clock,
        );
    }
}
