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
        copy(
            self::projectRoot() . '/migrations/0001_core.sql',
            $this->sandboxRoot . '/migrations/0001_core.sql'
        );

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

    private function release(string $version): ReleaseInfo
    {
        return new ReleaseInfo(
            $version,
            'v' . $version,
            'secondstay-' . $version . '.zip',
            'https://example.test/secondstay-' . $version . '.zip',
            1024,
            gmdate('c'),
            'Notes de version ' . $version,
        );
    }

    public function testCheckDetectsANewerRelease(): void
    {
        $this->provider->addRelease($this->release('1.1.0'), $this->buildArtifact('1.1.0'));

        $state = $this->updates->check();

        self::assertTrue($state['available']);
        self::assertSame('1.0.0', $state['current']);
        self::assertSame('1.1.0', $state['latest']?->version);
    }

    public function testCheckIgnoresOlderOrEqualReleases(): void
    {
        $this->provider->addRelease($this->release('1.0.0'), $this->buildArtifact('1.0.0'));

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
        $this->provider->addRelease($this->release('1.1.0'), $this->buildArtifact('1.1.0'));
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
        $this->provider->addRelease($this->release('1.1.0'), $this->buildArtifact('1.1.0'));
        $result = $this->updates->install($this->latestRelease());

        self::assertNotNull($result['backup']);
        self::assertFileExists((string) $result['backup']);
    }

    public function testAnArtifactCarryingRuntimeDataIsRefusedBeforeInstall(): void
    {
        $this->provider->addRelease($this->release('1.1.0'), $this->buildArtifact('1.1.0', [
            'storage/documents/facture.txt' => 'contenu malveillant',
            'config/local.php' => "<?php return ['keep' => false];\n",
        ]));

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

        $this->provider->addRelease($this->release('1.1.0'), $this->buildArtifact('1.1.0'));
        $result = $this->updates->install($this->latestRelease());

        self::assertTrue($result['installed']);
        self::assertSame('donnée persistante', file_get_contents($this->sandboxPaths->storage('documents/facture.txt')));
        self::assertStringContainsString("'keep' => true", (string) file_get_contents($this->sandboxRoot . '/config/local.php'));
    }

    public function testFailedMigrationTriggersRollback(): void
    {
        $this->provider->addRelease($this->release('1.2.0'), $this->buildArtifact('1.2.0', [
            'migrations/0002_broken.sql' => "CREATE TABLE `broken` (this is not valid sql);\n",
        ]));

        $result = $this->updates->install($this->latestRelease(), 'owner@example.test', 1);

        self::assertFalse($result['installed']);
        self::assertTrue($result['rolled_back']);
        self::assertSame('1.0.0', trim((string) file_get_contents($this->sandboxRoot . '/VERSION')));
        self::assertStringContainsString('ancienne version', (string) file_get_contents($this->sandboxRoot . '/public/index.php'));
        self::assertFileDoesNotExist($this->sandboxRoot . '/migrations/0002_broken.sql');

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');
        self::assertContains('update.rolled_back', $actions);
    }

    public function testMaintenanceIsLiftedAfterInstall(): void
    {
        $this->provider->addRelease($this->release('1.1.0'), $this->buildArtifact('1.1.0'));
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
        $this->provider->addRelease($this->release('1.1.0'), $this->buildArtifact('1.1.0'));
        $this->updates->install($this->latestRelease(), 'owner@example.test', 1);

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');
        self::assertContains('update.installed', $actions);
    }

    public function testHealthCheckFailsWhenMigrationsArePending(): void
    {
        self::assertTrue($this->updates->healthCheck());

        file_put_contents($this->sandboxRoot . '/migrations/0002_pending.sql', "SELECT 1;\n");

        self::assertFalse($this->updates->healthCheck());
    }
}
