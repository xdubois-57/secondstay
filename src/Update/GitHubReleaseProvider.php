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

    public function latest(bool $allowPrerelease = false): ?ReleaseInfo
    {
        $response = $this->http->getJson(
            'https://api.github.com/repos/' . $this->repository . '/releases?per_page=10',
            ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'SecondStay-Updater']
        );

        if (!is_array($response)) {
            return null;
        }

        foreach ($response as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['draft'] ?? false) === true) {
                continue;
            }
            $prerelease = ($entry['prerelease'] ?? false) === true;
            if ($prerelease && !$allowPrerelease) {
                continue;
            }

            $tag = (string) ($entry['tag_name'] ?? '');
            if (preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $matches) !== 1) {
                continue;
            }

            /** @var list<array<string, mixed>> $assets */
            $assets = is_array($entry['assets'] ?? null) ? $entry['assets'] : [];
            foreach ($assets as $asset) {
                $name = (string) ($asset['name'] ?? '');
                if (preg_match(self::ASSET_PATTERN, $name) !== 1) {
                    continue;
                }

                return new ReleaseInfo(
                    $matches[1],
                    $tag,
                    $name,
                    (string) ($asset['browser_download_url'] ?? ''),
                    (int) ($asset['size'] ?? 0),
                    (string) ($entry['published_at'] ?? ''),
                    (string) ($entry['body'] ?? ''),
                    $prerelease,
                );
            }
        }

        return null;
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
