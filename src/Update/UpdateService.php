<?php

declare(strict_types=1);

namespace SecondStay\Update;

use RuntimeException;
use SecondStay\Audit\AuditTrail;
use SecondStay\Backup\BackupService;
use SecondStay\Core\Paths;
use SecondStay\Database\Database;
use SecondStay\Database\Migrator;
use SecondStay\Logging\Logger;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Release\ReleaseArtifactInspector;
use Throwable;
use ZipArchive;

/**
 * Mise à jour intégrée (RELEASE.md §11).
 *
 * Flux : check → download → validate → backup → maintenance → install →
 * migrations → VERSION → health check → rollback si échec.
 *
 * Les données persistantes (`storage/`, `config/local.php`) ne sont jamais
 * remplacées.
 */
final class UpdateService
{
    /** @var list<string> chemins remplacés par la mise à jour */
    public const MANAGED_PATHS = ['public', 'src', 'templates', 'translations', 'migrations', 'vendor', '.htaccess'];

    public function __construct(
        private readonly ReleaseProvider $provider,
        private readonly Paths $paths,
        private readonly Database $database,
        private readonly BackupService $backups,
        private readonly MaintenanceMode $maintenance,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    public function currentVersion(): string
    {
        $file = $this->paths->root('VERSION');
        if (!is_file($file)) {
            return '0.0.0';
        }

        $content = file_get_contents($file);

        return $content === false ? '0.0.0' : trim($content);
    }

    /**
     * @return array{available: bool, current: string, latest: ?ReleaseInfo}
     */
    public function check(bool $allowPrerelease = false): array
    {
        $current = $this->currentVersion();
        $latest = $this->provider->latest($allowPrerelease);

        return [
            'available' => $latest !== null && version_compare($latest->version, $current, '>'),
            'current' => $current,
            'latest' => $latest,
        ];
    }

    /**
     * Valide l'archive téléchargée avant toute installation.
     *
     * @return list<string> problèmes bloquants
     */
    public function validateArtifact(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            return ['update.error.asset_missing'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['update.error.asset_unreadable'];
        }

        $version = $zip->getFromName('VERSION');
        $zip->close();

        if ($version === false || preg_match('/^\d+\.\d+\.\d+$/', trim($version)) !== 1) {
            return ['update.error.asset_no_version'];
        }

        $problems = (new ReleaseArtifactInspector())->inspect($zipPath);

        return array_map(
            static fn (string $problem): string => 'update.error.artifact:' . $problem,
            $problems
        );
    }

    /**
     * Installe une release.
     *
     * @return array{installed: bool, version: string, migrations: list<string>, backup: ?string, rolled_back: bool}
     */
    public function install(ReleaseInfo $release, ?string $actorLabel = null, ?int $actorId = null): array
    {
        $previousVersion = $this->currentVersion();
        $workDirectory = $this->paths->storage('temp/update-' . bin2hex(random_bytes(6)));
        if (!mkdir($workDirectory, 0o750, true) && !is_dir($workDirectory)) {
            throw new RuntimeException('Répertoire de mise à jour inaccessible.');
        }

        $assetPath = $workDirectory . '/' . $release->assetName;
        $rollbackDirectory = $workDirectory . '/rollback';
        $backupPath = null;
        $rolledBack = false;

        try {
            $this->provider->download($release, $assetPath);

            $problems = $this->validateArtifact($assetPath);
            if ($problems !== []) {
                throw new RuntimeException('Artefact invalide : ' . implode(', ', $problems));
            }

            $backup = $this->backups->create(true, $actorLabel, $actorId);
            $backupPath = $backup['path'];

            return $this->maintenance->during('maintenance.reason.update', function () use (
                $release,
                $assetPath,
                $rollbackDirectory,
                $previousVersion,
                $backupPath,
                $actorLabel,
                $actorId,
                &$rolledBack
            ): array {
                $this->snapshotManagedPaths($rollbackDirectory);

                try {
                    $this->extractArtifact($assetPath);

                    $migrator = new Migrator($this->database, $this->paths->migrations());
                    $applied = $migrator->migrate();

                    file_put_contents($this->paths->root('VERSION'), $release->version . "\n");

                    if (!$this->healthCheck()) {
                        throw new RuntimeException('Contrôle de santé post-installation en échec.');
                    }

                    $this->audit?->record(
                        'update.installed',
                        'update',
                        $release->version,
                        ['version' => $previousVersion],
                        ['version' => $release->version, 'migrations' => count($applied)],
                        $actorId,
                        $actorLabel ?? 'system',
                    );
                    $this->logger->info('update', 'Mise à jour installée', [
                        'from' => $previousVersion,
                        'to' => $release->version,
                    ]);

                    return [
                        'installed' => true,
                        'version' => $release->version,
                        'migrations' => $applied,
                        'backup' => $backupPath,
                        'rolled_back' => false,
                    ];
                } catch (Throwable $throwable) {
                    $this->restoreManagedPaths($rollbackDirectory);
                    file_put_contents($this->paths->root('VERSION'), $previousVersion . "\n");
                    $rolledBack = true;

                    $this->logger->error('update', 'Mise à jour annulée', [
                        'from' => $previousVersion,
                        'to' => $release->version,
                        'reason' => $throwable->getMessage(),
                    ]);
                    $this->audit?->record(
                        'update.rolled_back',
                        'update',
                        $release->version,
                        ['version' => $previousVersion],
                        ['reason' => $throwable->getMessage()],
                        $actorId,
                        $actorLabel ?? 'system',
                    );

                    return [
                        'installed' => false,
                        'version' => $previousVersion,
                        'migrations' => [],
                        'backup' => $backupPath,
                        'rolled_back' => true,
                    ];
                }
            });
        } finally {
            $this->removeDirectory($workDirectory);
        }
    }

    public function healthCheck(): bool
    {
        try {
            if (!$this->database->isReachable()) {
                return false;
            }
            if (!is_file($this->paths->public('index.php'))) {
                return false;
            }
            if (!is_file($this->paths->root('vendor/autoload.php'))) {
                return false;
            }
            $migrator = new Migrator($this->database, $this->paths->migrations());

            return $migrator->pending() === [];
        } catch (Throwable) {
            return false;
        }
    }

    private function snapshotManagedPaths(string $destination): void
    {
        if (!mkdir($destination, 0o750, true) && !is_dir($destination)) {
            throw new RuntimeException('Instantané de restauration impossible.');
        }

        foreach (array_merge(self::MANAGED_PATHS, ['VERSION']) as $relative) {
            $source = $this->paths->root($relative);
            if (file_exists($source)) {
                $this->copyPath($source, $destination . '/' . $relative);
            }
        }
    }

    private function restoreManagedPaths(string $snapshot): void
    {
        foreach (array_merge(self::MANAGED_PATHS, ['VERSION']) as $relative) {
            $source = $snapshot . '/' . $relative;
            if (!file_exists($source)) {
                continue;
            }
            $target = $this->paths->root($relative);
            if (is_dir($target)) {
                $this->removeDirectory($target);
            } elseif (is_file($target)) {
                unlink($target);
            }
            $this->copyPath($source, $target);
        }
    }

    private function extractArtifact(string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Archive de mise à jour illisible.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }

                $target = $this->safeTarget($name);
                if ($target === null) {
                    continue;
                }

                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
                    throw new RuntimeException('Répertoire cible inaccessible : ' . $directory);
                }

                $contents = $zip->getFromIndex($index);
                if ($contents === false) {
                    throw new RuntimeException('Entrée illisible : ' . $name);
                }

                if (file_put_contents($target, $contents) === false) {
                    throw new RuntimeException('Écriture impossible : ' . $target);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Refuse toute traversée de chemin et n'écrit que dans les répertoires gérés.
     */
    private function safeTarget(string $entryName): ?string
    {
        $relative = str_replace('\\', '/', $entryName);
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('Entrée d’archive refusée : ' . $entryName);
        }

        // Les données de l'installation ne sont jamais écrasées.
        if (str_starts_with($relative, 'storage/') || $relative === 'config/local.php') {
            return null;
        }

        $first = explode('/', $relative)[0];
        $allowed = array_merge(self::MANAGED_PATHS, ['VERSION', 'LICENSE', 'config']);
        if (!in_array($first, $allowed, true)) {
            return null;
        }

        return $this->paths->root($relative);
    }

    private function copyPath(string $source, string $destination): void
    {
        if (is_file($source)) {
            $directory = dirname($destination);
            if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
                throw new RuntimeException('Copie impossible : ' . $directory);
            }
            copy($source, $destination);

            return;
        }

        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($destination) && !mkdir($destination, 0o750, true) && !is_dir($destination)) {
            throw new RuntimeException('Copie impossible : ' . $destination);
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->copyPath($source . '/' . $entry, $destination . '/' . $entry);
        }
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
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
