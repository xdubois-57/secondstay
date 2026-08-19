<?php

declare(strict_types=1);

namespace SecondStay\Release;

use RuntimeException;
use ZipArchive;

/**
 * Construit le ZIP de production à partir d'une copie de travail propre.
 *
 * Le ZIP est l'unique unité installable (RELEASE.md §1) : il contient le code
 * de production et `vendor/` optimisé, et rien d'autre.
 */
final class ReleaseArtifactBuilder
{
    /** @var list<string> */
    private array $devPackageDirectories = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $stagingDirectory,
    ) {
    }

    /**
     * @return list<string> entrées du ZIP produit
     */
    public function build(string $zipPath): array
    {
        $this->resetStaging();
        $this->copyIncludedPaths();
        $this->copyVendor();
        $this->regenerateAutoloader();
        $this->pruneVendor();

        return $this->writeZip($zipPath);
    }

    private function resetStaging(): void
    {
        self::removeDirectory($this->stagingDirectory);
        if (!mkdir($this->stagingDirectory, 0o750, true) && !is_dir($this->stagingDirectory)) {
            throw new RuntimeException('Impossible de préparer ' . $this->stagingDirectory);
        }
    }

    private function copyIncludedPaths(): void
    {
        foreach (ReleaseArtifactPolicy::INCLUDED_PATHS as $relative) {
            $source = $this->projectRoot . '/' . $relative;
            if (!file_exists($source)) {
                throw new RuntimeException('Chemin de release introuvable : ' . $relative);
            }
            $this->copy($source, $this->stagingDirectory . '/' . $relative);
        }
    }

    private function copyVendor(): void
    {
        $vendor = $this->projectRoot . '/vendor';
        if (!is_dir($vendor)) {
            throw new RuntimeException('vendor/ est absent : lancez composer install.');
        }

        $this->devPackageDirectories = $this->developmentPackageDirectories();
        $this->copy($vendor, $this->stagingDirectory . '/vendor', function (string $absolute): bool {
            $relative = substr($absolute, strlen($this->projectRoot . '/vendor/'));
            foreach ($this->devPackageDirectories as $devPackage) {
                if ($relative === $devPackage || str_starts_with($relative, $devPackage . '/')) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Les paquets `require-dev` ne doivent jamais atteindre la production.
     *
     * @return list<string>
     */
    private function developmentPackageDirectories(): array
    {
        $lockFile = $this->projectRoot . '/composer.lock';
        if (!is_file($lockFile)) {
            return [];
        }

        $raw = file_get_contents($lockFile);
        if ($raw === false) {
            return [];
        }

        /** @var array{packages-dev?: list<array{name?: string}>} $lock */
        $lock = json_decode($raw, true);
        $directories = [];
        foreach ($lock['packages-dev'] ?? [] as $package) {
            $name = $package['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $directories[] = $name;
            }
        }

        return $directories;
    }

    /**
     * L'autoloader du dépôt référence les paquets de développement.
     * L'artefact de production doit embarquer un autoloader `--no-dev`.
     */
    private function regenerateAutoloader(): void
    {
        foreach (['composer.json', 'composer.lock'] as $file) {
            $source = $this->projectRoot . '/' . $file;
            if (is_file($source)) {
                copy($source, $this->stagingDirectory . '/' . $file);
            }
        }

        $command = sprintf(
            'COMPOSER_ALLOW_SUPERUSER=1 %s dump-autoload --no-dev --optimize --classmap-authoritative '
            . '--no-interaction --no-scripts --working-dir=%s 2>&1',
            escapeshellarg($this->composerBinary()),
            escapeshellarg($this->stagingDirectory)
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);

        foreach (['composer.json', 'composer.lock'] as $file) {
            $path = $this->stagingDirectory . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }

        if ($status !== 0) {
            throw new RuntimeException(
                'Regénération de l’autoloader de production impossible : ' . implode("\n", $output)
            );
        }

        if (!is_file($this->stagingDirectory . '/vendor/autoload.php')) {
            throw new RuntimeException('vendor/autoload.php absent après regénération.');
        }
    }

    private function composerBinary(): string
    {
        $candidates = [getenv('COMPOSER_BINARY') ?: '', 'composer', 'composer.phar'];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $output = [];
            $status = 0;
            exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null', $output, $status);
            if ($status === 0 && isset($output[0]) && $output[0] !== '') {
                return $output[0];
            }
        }

        throw new RuntimeException('Composer est requis pour construire un artefact de release.');
    }

    private function pruneVendor(): void
    {
        $vendor = $this->stagingDirectory . '/vendor';
        if (!is_dir($vendor)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendor, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($vendor) + 1);

            if ($item->isDir()) {
                // Uniquement à la racine d'un paquet (vendor/<vendor>/<package>/<dir>) :
                // plus profond, `Test/` peut être un vrai répertoire de code source.
                if (
                    substr_count($relative, '/') === 2
                    && in_array($item->getFilename(), ReleaseArtifactPolicy::VENDOR_PRUNED_DIRECTORIES, true)
                ) {
                    self::removeDirectory($item->getPathname());
                }
                continue;
            }

            foreach (ReleaseArtifactPolicy::VENDOR_PRUNED_FILE_PATTERNS as $pattern) {
                if (substr_count($relative, '/') <= 2 && preg_match($pattern, $relative) === 1) {
                    unlink($item->getPathname());
                    break;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function writeZip(string $zipPath): array
    {
        $directory = dirname($zipPath);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer ' . $directory);
        }
        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer l’archive ' . $zipPath);
        }

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stagingDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($this->stagingDirectory) + 1);
            $relative = str_replace('\\', '/', $relative);
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }
            $zip->addFile($item->getPathname(), $relative);
            $entries[] = $relative;
        }

        $zip->close();
        sort($entries);

        return $entries;
    }

    /**
     * @param null|callable(string): bool $filter
     */
    private function copy(string $source, string $destination, ?callable $filter = null): void
    {
        if (is_file($source)) {
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0o750, true) && !is_dir($parent)) {
                throw new RuntimeException('Impossible de créer ' . $parent);
            }
            if (!copy($source, $destination)) {
                throw new RuntimeException('Copie impossible : ' . $source);
            }

            return;
        }

        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($destination) && !mkdir($destination, 0o750, true) && !is_dir($destination)) {
            throw new RuntimeException('Impossible de créer ' . $destination);
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $childSource = $source . '/' . $entry;
            if ($filter !== null && !$filter($childSource)) {
                continue;
            }
            $this->copy($childSource, $destination . '/' . $entry, $filter);
        }
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
