<?php

declare(strict_types=1);

namespace SecondStay\Update;

use RuntimeException;
use SecondStay\Http\HttpFetcher;

/**
 * Source officielle des mises à jour : les GitHub Releases du dépôt
 * (RELEASE.md §1). Aucun ZIP arbitraire de `main` n'est installable.
 */
final class GitHubReleaseProvider implements ReleaseProvider
{
    public const ASSET_PATTERN = '/^secondstay-\d+\.\d+\.\d+\.zip$/';

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly string $repository = 'xdubois-57/secondstay',
    ) {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
            throw new RuntimeException('Dépôt de mise à jour invalide.');
        }
    }

    /**
     * La release retenue est la **plus haute version** éligible, pas la
     * première de la liste : l'API trie par date de création, et un correctif
     * publié sur une branche plus ancienne ne doit pas masquer la dernière
     * version.
     */
    public function latest(bool $allowPrerelease = false): ?ReleaseInfo
    {
        $response = $this->http->getJson(
            'https://api.github.com/repos/' . $this->repository . '/releases?per_page=10',
            ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'SecondStay-Updater']
        );

        if (!is_array($response)) {
            return null;
        }

        $best = null;
        foreach ($response as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $candidate = $this->candidate($entry, $allowPrerelease);
            if ($candidate !== null && ($best === null || version_compare($candidate->version, $best->version, '>'))) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param array<mixed> $entry
     */
    private function candidate(array $entry, bool $allowPrerelease): ?ReleaseInfo
    {
        if (($entry['draft'] ?? false) === true) {
            return null;
        }
        $prerelease = ($entry['prerelease'] ?? false) === true;
        if ($prerelease && !$allowPrerelease) {
            return null;
        }

        $tag = is_string($entry['tag_name'] ?? null) ? $entry['tag_name'] : '';
        if (preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $matches) !== 1) {
            return null;
        }

        $assets = is_array($entry['assets'] ?? null) ? $entry['assets'] : [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = is_string($asset['name'] ?? null) ? $asset['name'] : '';
            // L'archive doit porter la version de son étiquette : une release
            // v1.4.0 qui joindrait secondstay-9.9.9.zip n'offre rien.
            if (preg_match(self::ASSET_PATTERN, $name) !== 1 || $name !== 'secondstay-' . $matches[1] . '.zip') {
                continue;
            }

            return new ReleaseInfo(
                $matches[1],
                $tag,
                $name,
                is_string($asset['browser_download_url'] ?? null) ? $asset['browser_download_url'] : '',
                is_int($asset['size'] ?? null) ? $asset['size'] : 0,
                is_string($entry['published_at'] ?? null) ? $entry['published_at'] : '',
                is_string($entry['body'] ?? null) ? $entry['body'] : '',
                $prerelease,
                self::sha256FromDigest(is_string($asset['digest'] ?? null) ? $asset['digest'] : ''),
            );
        }

        return null;
    }

    /**
     * GitHub publie l'empreinte d'un asset sous la forme `sha256:<hex>`.
     * Toute autre forme — absente, autre algorithme, longueur fausse — est
     * traitée comme « pas d'empreinte » : la release ne sera pas installable.
     */
    public static function sha256FromDigest(string $digest): string
    {
        if (preg_match('/^sha256:([0-9a-f]{64})$/i', trim($digest), $matches) !== 1) {
            return '';
        }

        return strtolower($matches[1]);
    }

    public function download(ReleaseInfo $release, string $destination): int
    {
        if ($release->assetUrl === '') {
            throw new RuntimeException('Asset de release introuvable.');
        }

        return $this->http->download($release->assetUrl, $destination, [
            'Accept' => 'application/octet-stream',
            'User-Agent' => 'SecondStay-Updater',
        ]);
    }
}
