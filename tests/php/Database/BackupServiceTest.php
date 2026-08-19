<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Backup\BackupManifest;
use SecondStay\Backup\BackupService;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Tests\Support\DatabaseTestCase;
use ZipArchive;

final class BackupServiceTest extends DatabaseTestCase
{
    private BackupService $backups;

    private MaintenanceMode $maintenance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maintenance = new MaintenanceMode($this->paths->storage('maintenance.json'));
        $this->backups = new BackupService(
            $this->database,
            $this->paths,
            $this->maintenance,
            '1.2.3',
            new AuditTrail($this->database),
        );

        $this->seed();
    }

    private function seed(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->database->insert('setting', [
            'key' => 'property.name',
            'value' => 'Maison des Pins — été 2026',
            'is_secret' => 0,
            'updated_at' => $now,
        ]);
        $this->database->insert('user', [
            'email' => 'owner@example.test',
            'password_hash' => 'hash',
            'first_name' => 'Claire',
            'last_name' => 'Dubois',
            'role' => 'administrator',
            'locale' => 'fr',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        file_put_contents($this->paths->storage('documents/contrat.txt'), 'Contenu du contrat');
        file_put_contents($this->paths->storage('media/photo.txt'), 'Photo factice');
    }

    public function testBackupContainsDumpMediaAndManifest(): void
    {
        $result = $this->backups->create();

        self::assertFileExists($result['path']);
        self::assertSame('1.2.3', $result['manifest']->appVersion);
        self::assertSame('0001', $result['manifest']->schemaVersion);
        self::assertArrayHasKey('user', $result['manifest']->tableRows);
        self::assertSame(1, $result['manifest']->tableRows['user']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($result['path']) === true);
        self::assertNotFalse($zip->locateName(BackupService::SQL_ENTRY));
        self::assertNotFalse($zip->locateName(BackupManifest::FILENAME));
        self::assertNotFalse($zip->locateName('storage/documents/contrat.txt'));
        self::assertNotFalse($zip->locateName('storage/media/photo.txt'));
        $zip->close();
    }

    public function testVerificationDetectsCorruption(): void
    {
        $result = $this->backups->create();

        self::assertSame([], $this->backups->verify($result['path'])['problems']);

        $zip = new ZipArchive();
        $zip->open($result['path']);
        $zip->addFromString('storage/documents/contrat.txt', 'contenu altéré');
        $zip->close();

        $verification = $this->backups->verify($result['path']);
        self::assertFalse($verification['ok']);
        self::assertStringContainsString('backup.error.checksum', implode(' ', $verification['problems']));
    }

    public function testRestoreBringsBackDataAndFiles(): void
    {
        $result = $this->backups->create();

        $this->database->execute('DELETE FROM `setting`');
        $this->database->execute('DELETE FROM `user`');
        unlink($this->paths->storage('documents/contrat.txt'));

        $restore = $this->backups->restore($result['path']);

        self::assertGreaterThan(0, $restore['restored_statements']);
        self::assertSame(2, $restore['restored_files']);
        self::assertSame(
            'Maison des Pins — été 2026',
            (string) $this->database->fetchValue('SELECT `value` FROM `setting` WHERE `key` = :k', ['k' => 'property.name'])
        );
        self::assertSame(1, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `user`'));
        self::assertSame('Contenu du contrat', file_get_contents($this->paths->storage('documents/contrat.txt')));
    }

    public function testRestoreIsAudited(): void
    {
        $result = $this->backups->create();
        $this->backups->restore($result['path'], 'owner@example.test', 1);

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');
        self::assertContains('backup.restored', $actions);
    }

    public function testRestoreRefusesPathTraversalEntries(): void
    {
        $result = $this->backups->create();

        $zip = new ZipArchive();
        $zip->open($result['path']);
        $zip->addFromString('storage/../../evil.txt', 'pwned');
        $zip->close();

        // L'entrée hostile n'est pas dans le manifeste : la vérification la
        // laisse passer, mais la restauration doit la refuser.
        $this->expectException(\RuntimeException::class);
        $this->backups->restore($result['path']);
    }

    public function testRestoreRefusesUnknownStorageDirectory(): void
    {
        $result = $this->backups->create();

        $zip = new ZipArchive();
        $zip->open($result['path']);
        $zip->addFromString('storage/logs/secret.log', 'ne doit pas être restauré');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->backups->restore($result['path']);
    }

    public function testPathForRejectsInvalidIdentifiers(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backups->pathFor('../../etc/passwd');
    }

    public function testRetentionKeepsTheConfiguredNumberOfBackups(): void
    {
        for ($index = 0; $index < 4; $index++) {
            $this->backups->create(false);
        }

        self::assertCount(4, $this->backups->list());

        $removed = $this->backups->applyRetention(2);

        self::assertCount(2, $removed);
        self::assertCount(2, $this->backups->list());
    }

    public function testDiskUsageIsReported(): void
    {
        $this->backups->create(false);

        self::assertGreaterThan(0, $this->backups->diskUsage());
    }

    public function testMaintenanceIsRestoredAfterRestore(): void
    {
        $result = $this->backups->create(false);

        self::assertFalse($this->maintenance->isActive());
        $this->backups->restore($result['path']);
        self::assertFalse($this->maintenance->isActive());
    }

    public function testBackupWithoutMediaExcludesFiles(): void
    {
        $result = $this->backups->create(false);

        $zip = new ZipArchive();
        $zip->open($result['path']);
        self::assertFalse($zip->locateName('storage/documents/contrat.txt'));
        $zip->close();

        self::assertFalse($result['manifest']->includesMedia);
    }
}
