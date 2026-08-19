<?php

declare(strict_types=1);

namespace SecondStay\Update;

use RuntimeException;

/**
 * Fournisseur de release factice : permet de tester intégralement le flux de
 * mise à jour sans réseau (TESTING.md §8).
 */
final class FakeReleaseProvider implements ReleaseProvider
{
    /** @var array<string, string> version => chemin local de l'archive */
    private array $assets = [];

    /** @var list<ReleaseInfo> */
    private array $releases = [];

    public int $downloadCount = 0;

    public bool $failDownload = false;

    public function addRelease(ReleaseInfo $release, string $localAssetPath): void
    {
        $this->releases[] = $release;
        $this->assets[$release->version] = $localAssetPath;

        usort(
            $this->releases,
            static fn (ReleaseInfo $a, ReleaseInfo $b): int => version_compare($b->version, $a->version)
        );
    }

    public function latest(bool $allowPrerelease = false): ?ReleaseInfo
    {
        foreach ($this->releases as $release) {
            if ($release->prerelease && !$allowPrerelease) {
                continue;
            }

            return $release;
        }

        return null;
    }

    public function download(ReleaseInfo $release, string $destination): int
    {
        if ($this->failDownload) {
            throw new RuntimeException('Téléchargement simulé en échec.');
        }

        $this->downloadCount++;
        $source = $this->assets[$release->version] ?? null;
        if ($source === null || !is_file($source)) {
            throw new RuntimeException('Asset factice introuvable pour ' . $release->version);
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Destination de téléchargement inaccessible.');
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException('Copie de l’asset factice impossible.');
        }

        return filesize($destination) ?: 0;
    }
}
