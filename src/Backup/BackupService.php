<?php

declare(strict_types=1);

namespace SecondStay\Backup;

use RuntimeException;
use SecondStay\Audit\AuditTrail;
use SecondStay\Core\Paths;
use SecondStay\Database\Database;
use SecondStay\Database\Migrator;
use SecondStay\Maintenance\MaintenanceMode;
use Throwable;
use ZipArchive;

/**
 * Sauvegarde et restauration 100 % PHP (AGENTS.md §1.8, SECURITY.md §18).
 *
 * Une sauvegarde contient :
 *  - le dump SQL complet ;
 *  - les répertoires persistants de `storage/` (médias, documents,
 *    états des lieux, pièces jointes) ;
 *  - un manifeste avec empreintes SHA-256 de chaque entrée.
 *
 * Le code applicatif n'est pas sauvegardé : il provient des GitHub Releases.
 */
final class BackupService
{
    public const SQL_ENTRY = 'database.sql';

    /**
     * Répertoires persistants inclus.
     *
     * Les photos d'état des lieux et d'incident sont des documents ordinaires
     * (SPECIFICATIONS.md §53) : elles vivent sous `documents/`, et sont donc
     * déjà couvertes. `inspections/` reste listé pour les sauvegardes
     * produites par une version antérieure, où le répertoire pouvait exister.
     *
     * @var list<string>
     */
    public const DATA_DIRECTORIES = ['media', 'documents', 'inspections', 'mail-attachments'];

    public function __construct(
        private readonly Database $database,
        private readonly Paths $paths,
        private readonly MaintenanceMode $maintenance,
        private readonly string $appVersion,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    public function directory(): string
    {
        $directory = $this->paths->storage('backups');
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Répertoire de sauvegarde inaccessible.');
        }

        return $directory;
    }

    /**
     * @return array{path: string, manifest: BackupManifest}
     */
    public function create(bool $includeMedia = true, ?string $actorLabel = null, ?int $actorId = null): array
    {
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $path = $this->directory() . '/secondstay-backup-' . $id . '.zip';
        $temporarySql = $this->paths->storage('temp') . '/backup-' . $id . '.sql';

        $handle = fopen($temporarySql, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Impossible de préparer le dump SQL.');
        }

        try {
            $tableRows = (new SqlDumper($this->database))->dumpTo($handle);
        } finally {
            fclose($handle);
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($temporarySql);
            throw new RuntimeException('Impossible de créer l’archive de sauvegarde.');
        }

        $checksums = [];
        $zip->addFile($temporarySql, self::SQL_ENTRY);
        $sqlHash = hash_file('sha256', $temporarySql);
        $checksums[self::SQL_ENTRY] = $sqlHash === false ? '' : $sqlHash;

        if ($includeMedia) {
            foreach (self::DATA_DIRECTORIES as $directory) {
                $absolute = $this->paths->storage($directory);
                if (!is_dir($absolute)) {
                    continue;
                }
                foreach ($this->filesIn($absolute) as $file) {
                    $relative = 'storage/' . $directory . '/' . substr($file, strlen($absolute) + 1);
                    $relative = str_replace('\\', '/', $relative);
                    $zip->addFile($file, $relative);
                    $hash = hash_file('sha256', $file);
                    $checksums[$relative] = $hash === false ? '' : $hash;
                }
            }
        }

        $manifest = new BackupManifest(
            $id,
            gmdate('c'),
            $this->appVersion,
            (string) (new Migrator($this->database, $this->paths->migrations()))->currentVersion(),
            $checksums,
            $tableRows,
            $includeMedia,
        );

        $zip->addFromString(BackupManifest::FILENAME, $manifest->toJson());
        $zip->close();

        @unlink($temporarySql);
        @chmod($path, 0o600);

        $this->audit?->record(
            'backup.created',
            'backup',
            $id,
            null,
            ['size' => filesize($path) ?: 0, 'includes_media' => $includeMedia],
            $actorId,
            $actorLabel ?? 'system',
        );

        return ['path' => $path, 'manifest' => $manifest];
    }

    /**
     * @return list<array{id: string, path: string, size: int, created_at: string}>
     */
    public function list(): array
    {
        $files = glob($this->directory() . '/secondstay-backup-*.zip');
        if ($files === false) {
            return [];
        }

        rsort($files);

        return array_map(
            static function (string $file): array {
                preg_match('/secondstay-backup-(.+)\.zip$/', $file, $matches);

                return [
                    'id' => $matches[1] ?? basename($file),
                    'path' => $file,
                    'size' => filesize($file) ?: 0,
                    'created_at' => gmdate('c', filemtime($file) ?: time()),
                ];
            },
            $files
        );
    }

    public function pathFor(string $id): string
    {
        // Aucune traversée de chemin possible : l'identifiant est strictement
        // contraint (SECURITY.md §18).
        if (preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id) !== 1) {
            throw new RuntimeException('Identifiant de sauvegarde invalide.');
        }

        $path = $this->directory() . '/secondstay-backup-' . $id . '.zip';
        if (!is_file($path)) {
            throw new RuntimeException('Sauvegarde introuvable.');
        }

        return $path;
    }

    /**
     * @return array{ok: bool, problems: list<string>, manifest: ?BackupManifest}
     */
    public function verify(string $path): array
    {
        $problems = [];

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['ok' => false, 'problems' => ['backup.error.unreadable'], 'manifest' => null];
        }

        $manifestRaw = $zip->getFromName(BackupManifest::FILENAME);
        if ($manifestRaw === false) {
            $zip->close();

            return ['ok' => false, 'problems' => ['backup.error.no_manifest'], 'manifest' => null];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($manifestRaw, true);
        if (!is_array($decoded)) {
            $zip->close();

            return ['ok' => false, 'problems' => ['backup.error.invalid_manifest'], 'manifest' => null];
        }

        $manifest = BackupManifest::fromArray($decoded);

        if ($manifest->formatVersion > BackupManifest::FORMAT_VERSION) {
            $problems[] = 'backup.error.format_too_recent';
        }

        if ($zip->locateName(self::SQL_ENTRY) === false) {
            $problems[] = 'backup.error.no_sql';
        }

        foreach ($manifest->checksums as $entry => $expected) {
            $contents = $zip->getFromName($entry);
            if ($contents === false) {
                $problems[] = 'backup.error.missing_entry:' . $entry;
                continue;
            }
            if (hash('sha256', $contents) !== $expected) {
                $problems[] = 'backup.error.checksum:' . $entry;
            }
        }

        $zip->close();

        return ['ok' => $problems === [], 'problems' => $problems, 'manifest' => $manifest];
    }

    /**
     * Restauration complète, sous maintenance et auditée.
     *
     * @return array{restored_statements: int, restored_files: int, manifest: BackupManifest}
     */
    public function restore(string $path, ?string $actorLabel = null, ?int $actorId = null): array
    {
        $verification = $this->verify($path);
        if ($verification['ok'] === false || $verification['manifest'] === null) {
            throw new RuntimeException('Sauvegarde invalide : ' . implode(', ', $verification['problems']));
        }

        $manifest = $verification['manifest'];

        return $this->maintenance->during(
            'maintenance.reason.restore',
            function () use ($path, $manifest, $actorLabel, $actorId): array {
                $zip = new ZipArchive();
                if ($zip->open($path) !== true) {
                    throw new RuntimeException('Archive de sauvegarde illisible.');
                }

                $temporary = $this->paths->storage('temp') . '/restore-' . bin2hex(random_bytes(6));
                if (!mkdir($temporary, 0o750, true) && !is_dir($temporary)) {
                    $zip->close();
                    throw new RuntimeException('Répertoire temporaire de restauration inaccessible.');
                }

                try {
                    $sql = $zip->getFromName(self::SQL_ENTRY);
                    if ($sql === false) {
                        throw new RuntimeException('Dump SQL absent de la sauvegarde.');
                    }
                    $sqlFile = $temporary . '/database.sql';
                    file_put_contents($sqlFile, $sql);

                    $statements = (new SqlDumper($this->database))->restoreFromFile($sqlFile);

                    $restoredFiles = 0;
                    for ($index = 0; $index < $zip->numFiles; $index++) {
                        $name = $zip->getNameIndex($index);
                        if ($name === false || !str_starts_with($name, 'storage/')) {
                            continue;
                        }

                        $target = $this->safeStorageTarget($name);
                        $directory = dirname($target);
                        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
                            throw new RuntimeException('Répertoire de restauration inaccessible.');
                        }

                        $contents = $zip->getFromIndex($index);
                        if ($contents === false) {
                            continue;
                        }
                        file_put_contents($target, $contents);
                        $restoredFiles++;
                    }

                    $this->audit?->record(
                        'backup.restored',
                        'backup',
                        $manifest->id,
                        null,
                        ['statements' => $statements, 'files' => $restoredFiles],
                        $actorId,
                        $actorLabel ?? 'system',
                    );

                    return [
                        'restored_statements' => $statements,
                        'restored_files' => $restoredFiles,
                        'manifest' => $manifest,
                    ];
                } finally {
                    $zip->close();
                    $this->removeDirectory($temporary);
                }
            }
        );
    }

    /**
     * Restauration de test : vérifie l'intégrité et l'exécutabilité du dump
     * sans toucher aux données courantes.
     *
     * @return array{ok: bool, problems: list<string>, tables: int}
     */
    public function testRestore(string $path, Database $scratchDatabase): array
    {
        $verification = $this->verify($path);
        if ($verification['ok'] === false) {
            return ['ok' => false, 'problems' => $verification['problems'], 'tables' => 0];
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['ok' => false, 'problems' => ['backup.error.unreadable'], 'tables' => 0];
        }

        $sql = $zip->getFromName(self::SQL_ENTRY);
        $zip->close();
        if ($sql === false) {
            return ['ok' => false, 'problems' => ['backup.error.no_sql'], 'tables' => 0];
        }

        $file = $this->paths->storage('temp') . '/test-restore-' . bin2hex(random_bytes(6)) . '.sql';
        file_put_contents($file, $sql);

        try {
            (new SqlDumper($scratchDatabase))->restoreFromFile($file);

            return ['ok' => true, 'problems' => [], 'tables' => count($scratchDatabase->tables())];
        } catch (Throwable $throwable) {
            return ['ok' => false, 'problems' => ['backup.error.restore_failed'], 'tables' => 0];
        } finally {
            @unlink($file);
        }
    }

    /**
     * Applique la rétention configurée.
     *
     * @return list<string> identifiants supprimés
     */
    public function applyRetention(int $keep): array
    {
        $removed = [];
        $backups = $this->list();
        foreach (array_slice($backups, max(1, $keep)) as $backup) {
            if (unlink($backup['path'])) {
                $removed[] = $backup['id'];
            }
        }

        return $removed;
    }

    public function diskUsage(): int
    {
        $total = 0;
        foreach ($this->list() as $backup) {
            $total += $backup['size'];
        }

        return $total;
    }

    private function safeStorageTarget(string $entryName): string
    {
        $relative = substr($entryName, strlen('storage/'));
        $relative = str_replace('\\', '/', $relative);

        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('Chemin de restauration refusé : ' . $entryName);
        }

        $segments = explode('/', $relative);
        if (!in_array($segments[0], self::DATA_DIRECTORIES, true)) {
            throw new RuntimeException('Répertoire de restauration non autorisé : ' . $segments[0]);
        }

        return $this->paths->storage($relative);
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
