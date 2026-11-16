<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SecondStay\Http\FakeHttpFetcher;
use SecondStay\Http\UrlGuard;
use SecondStay\Update\GitHubReleaseProvider;
use SecondStay\Update\ReleaseInfo;

/**
 * Source des mises à jour (RELEASE.md §1).
 *
 * Ce fournisseur décide **ce que le produit accepte de télécharger et
 * d'installer sur lui-même** : c'est la frontière la plus sensible du produit
 * après le paiement. Les règles vérifiées ici sont donc des règles de refus —
 * un brouillon, une préversion non demandée, une étiquette qui n'est pas une
 * version, une archive dont le nom ne suit pas la convention de release.
 */
final class GitHubReleaseProviderTest extends TestCase
{
    private const REPOSITORY = 'xdubois-57/secondstay';

    private const ENDPOINT = 'https://api.github.com/repos/xdubois-57/secondstay/releases?per_page=10';

    public function testAnInvalidRepositoryIsRefusedAtConstruction(): void
    {
        $this->expectException(RuntimeException::class);

        new GitHubReleaseProvider($this->fetcher(), 'https://evil.example/x');
    }

    public function testTheLatestPublishedReleaseIsReturned(): void
    {
        $http = $this->fetcher();
        $http->addJsonResponse(self::ENDPOINT, [
            $this->release('v1.4.0'),
            $this->release('v1.3.0'),
        ]);

        $release = $this->provider($http)->latest();

        self::assertInstanceOf(ReleaseInfo::class, $release);
        self::assertSame('1.4.0', $release->version);
        self::assertSame('secondstay-1.4.0.zip', $release->assetName);
        self::assertFalse($release->prerelease);
    }

    public function testADraftIsNeverOffered(): void
    {
        $http = $this->fetcher();
        $http->addJsonResponse(self::ENDPOINT, [
            $this->release('v2.0.0', draft: true),
            $this->release('v1.4.0'),
        ]);

        self::assertSame('1.4.0', $this->provider($http)->latest()?->version);
    }

    public function testAPrereleaseIsOnlyOfferedWhenAskedFor(): void
    {
        $http = $this->fetcher();
        $http->addJsonResponse(self::ENDPOINT, [
            $this->release('v2.0.0', prerelease: true),
            $this->release('v1.4.0'),
        ]);

        self::assertSame('1.4.0', $this->provider($http)->latest()?->version);
        self::assertSame('2.0.0', $this->provider($http)->latest(true)?->version);
    }

    /**
     * Une étiquette hors convention n'est pas une version : l'accepter
     * reviendrait à laisser un nom arbitraire décider de ce qui s'installe.
     */
    public function testATagThatIsNotAVersionIsIgnored(): void
    {
        $http = $this->fetcher();
        $http->addJsonResponse(self::ENDPOINT, [
            $this->release('nightly'),
            $this->release('v1.4.0'),
        ]);

        self::assertSame('1.4.0', $this->provider($http)->latest()?->version);
    }

    /**
     * Une release sans archive au nom attendu n'offre rien à installer :
     * c'est le seul motif d'exclusion qui protège contre un fichier joint
     * arbitraire attaché à une release légitime.
     */
    public function testAnAssetOutsideTheNamingConventionIsIgnored(): void
    {
        $http = $this->fetcher();
        $http->addJsonResponse(self::ENDPOINT, [
            [
                'tag_name' => 'v2.0.0',
                'draft' => false,
                'prerelease' => false,
                'published_at' => '2026-05-01T10:00:00Z',
                'body' => '',
                'assets' => [
                    ['name' => 'source.tar.gz', 'browser_download_url' => 'https://x.example/a', 'size' => 10],
                    ['name' => 'secondstay-2.0.0.zip.sig', 'browser_download_url' => 'https://x.example/b', 'size' => 10],
                ],
            ],
            $this->release('v1.4.0'),
        ]);

        self::assertSame('1.4.0', $this->provider($http)->latest()?->version);
    }

    public function testAnUnreachableApiOffersNothingRatherThanGuessing(): void
    {
        $http = $this->fetcher();
        // Aucune réponse enregistrée : le fournisseur reçoit un 404.

        self::assertNull($this->provider($http)->latest());
    }

    public function testAReleaseWithoutAnAssetUrlCannotBeDownloaded(): void
    {
        $release = new ReleaseInfo('1.4.0', 'v1.4.0', 'secondstay-1.4.0.zip', '', 0, '', '', false);

        $this->expectException(RuntimeException::class);

        $this->provider($this->fetcher())->download($release, sys_get_temp_dir() . '/ignore.zip');
    }

    /**
     * L'API liste par date de création : un correctif publié après coup sur
     * une branche plus ancienne arrive en tête et ne doit pas masquer la
     * dernière version.
     */
    public function testTheHighestVersionWinsWhateverTheListOrder(): void
    {
        $http = $this->fetcher();
        $http->addJsonResponse(self::ENDPOINT, [
            $this->release('v1.3.9'),
            $this->release('v1.10.0'),
            $this->release('v1.4.0'),
        ]);

        self::assertSame('1.10.0', $this->provider($http)->latest()?->version);
    }

    public function testTheDigestPublishedByGitHubIsCarried(): void
    {
        $digest = str_repeat('ab', 32);
        $http = $this->fetcher();
        $http->addJsonResponse(self::ENDPOINT, [$this->release('v1.4.0', digest: 'sha256:' . strtoupper($digest))]);

        self::assertSame($digest, $this->provider($http)->latest()?->digest);
    }

    /**
     * Un autre algorithme, une longueur fausse ou rien du tout : pas
     * d'empreinte, donc une release que l'updater refusera d'installer.
     */
    public function testAnythingButASha256DigestCountsAsNoDigest(): void
    {
        foreach (['', 'md5:' . str_repeat('a', 32), 'sha256:abc', 'sha512:' . str_repeat('a', 128)] as $digest) {
            self::assertSame('', GitHubReleaseProvider::sha256FromDigest($digest), $digest);
        }
    }

    public function testAnAssetCarryingAnotherVersionThanItsTagIsIgnored(): void
    {
        $release = $this->release('v2.0.0');
        $release['assets'][0]['name'] = 'secondstay-9.9.9.zip';

        $http = $this->fetcher();
        $http->addJsonResponse(self::ENDPOINT, [$release, $this->release('v1.4.0')]);

        self::assertSame('1.4.0', $this->provider($http)->latest()?->version);
    }

    private const DEV_ENDPOINT = 'https://api.github.com/repos/xdubois-57/secondstay/releases/tags/dev-latest';

    private const COMMIT = '0123456789abcdef0123456789abcdef01234567';

    public function testTheDevelopmentBuildIsReadFromItsTag(): void
    {
        $http = $this->fetcher();
        $http->addJsonResponse(self::DEV_ENDPOINT, $this->devRelease());

        $build = $this->provider($http)->devBuild();

        self::assertNotNull($build);
        self::assertSame('1.4.0-dev.0123456', $build->version);
        self::assertSame(self::COMMIT, $build->commit);
        self::assertSame('dev-latest', $build->tag);
        self::assertTrue($build->isDevBuild());
        self::assertSame(hash('sha256', 'dev'), $build->digest);
    }

    public function testADevelopmentAssetNamingAnotherCommitIsRefused(): void
    {
        // Archive d'un commit précédent laissée sur la release : elle n'est pas
        // celle que la release désigne.
        $http = $this->fetcher();
        $http->addJsonResponse(self::DEV_ENDPOINT, $this->devRelease('secondstay-1.4.0-dev.fffffff.zip'));

        self::assertNull($this->provider($http)->devBuild());
    }

    public function testADevelopmentReleaseWithoutCommitOrAsReleaseNameIsRefused(): void
    {
        $http = $this->fetcher();
        $http->addJsonResponse(self::DEV_ENDPOINT, ['target_commitish' => 'main'] + $this->devRelease());
        self::assertNull($this->provider($http)->devBuild());

        $http = $this->fetcher();
        $http->addJsonResponse(self::DEV_ENDPOINT, $this->devRelease('secondstay-1.4.0.zip'));
        self::assertNull($this->provider($http)->devBuild());

        $http = $this->fetcher();
        $http->addJsonResponse(self::DEV_ENDPOINT, ['draft' => true] + $this->devRelease());
        self::assertNull($this->provider($http)->devBuild());
    }

    public function testTheDevelopmentTagNeverReachesTheReleaseChannels(): void
    {
        // Même publiée en préversion, `dev-latest` n'est pas une étiquette
        // `vX.Y.Z` : ni le canal stable ni le canal préversion ne la voient.
        $http = $this->fetcher();
        $http->addJsonResponse(self::ENDPOINT, [['tag_name' => 'dev-latest'] + $this->devRelease(), $this->release('v1.3.0')]);

        self::assertSame('1.3.0', $this->provider($http)->latest(true)?->version);
    }

    /**
     * @return array<string, mixed>
     */
    private function devRelease(string $asset = 'secondstay-1.4.0-dev.0123456.zip'): array
    {
        return [
            'tag_name' => 'dev-latest',
            'target_commitish' => self::COMMIT,
            'draft' => false,
            'prerelease' => true,
            'published_at' => '2026-05-01T10:00:00Z',
            'body' => 'Construction roulante',
            'assets' => [[
                'name' => $asset,
                'browser_download_url' => 'https://github.example/download/dev.zip',
                'size' => 4_200_000,
                'digest' => 'sha256:' . hash('sha256', 'dev'),
            ]],
        ];
    }

    /**
     * @return array{tag_name: string, draft: bool, prerelease: bool, published_at: string, body: string,
     *     assets: list<array{name: string, browser_download_url: string, size: int, digest: string}>}
     */
    private function release(string $tag, bool $draft = false, bool $prerelease = false, string $digest = ''): array
    {
        $version = ltrim($tag, 'v');

        return [
            'tag_name' => $tag,
            'draft' => $draft,
            'prerelease' => $prerelease,
            'published_at' => '2026-05-01T10:00:00Z',
            'body' => 'Notes de version',
            'assets' => [[
                'name' => 'secondstay-' . $version . '.zip',
                'browser_download_url' => 'https://github.example/download/' . $version . '.zip',
                'size' => 4_200_000,
                'digest' => $digest !== '' ? $digest : 'sha256:' . hash('sha256', $version),
            ]],
        ];
    }

    private function fetcher(): FakeHttpFetcher
    {
        return new FakeHttpFetcher(new UrlGuard([], static function (string $host): array {
            return $host === 'api.github.com' ? ['140.82.121.5'] : [];
        }));
    }

    private function provider(FakeHttpFetcher $http): GitHubReleaseProvider
    {
        return new GitHubReleaseProvider($http, self::REPOSITORY);
    }
}
