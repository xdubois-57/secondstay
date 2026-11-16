<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Update\GitHubWebhook;

/**
 * Ce qui authentifie une notification GitHub, et ce qu'elle peut demander.
 */
final class GitHubWebhookTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testAGeneratedSecretIsLongAndRandom(): void
    {
        $first = GitHubWebhook::generateSecret();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, GitHubWebhook::generateSecret());
    }

    public function testOnlyTheExactSignatureOfTheBodyIsAccepted(): void
    {
        $body = '{"ref":"refs/heads/main"}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);

        self::assertTrue(GitHubWebhook::isSignatureValid(self::SECRET, $body, $signature));
        self::assertTrue(GitHubWebhook::isSignatureValid(self::SECRET, $body, strtoupper($signature)));

        self::assertFalse(GitHubWebhook::isSignatureValid(self::SECRET, $body . ' ', $signature));
        self::assertFalse(GitHubWebhook::isSignatureValid(self::SECRET, $body, null));
        self::assertFalse(GitHubWebhook::isSignatureValid(self::SECRET, $body, ''));
        self::assertFalse(GitHubWebhook::isSignatureValid(self::SECRET, $body, hash_hmac('sha256', $body, self::SECRET)));
        self::assertFalse(GitHubWebhook::isSignatureValid(
            self::SECRET,
            $body,
            'sha1=' . hash_hmac('sha1', $body, self::SECRET)
        ));
        self::assertFalse(GitHubWebhook::isSignatureValid(
            'autre-secret-autre-secret-autre-secret',
            $body,
            $signature
        ));
    }

    public function testAShortSecretNeverAuthenticatesAnything(): void
    {
        // Un secret vide signerait n'importe quoi avec une clé que tout le
        // monde connaît.
        $body = '{}';
        self::assertFalse(GitHubWebhook::isSignatureValid('', $body, 'sha256=' . hash_hmac('sha256', $body, '')));
        self::assertFalse(GitHubWebhook::isSignatureValid('court', $body, 'sha256=' . hash_hmac('sha256', $body, 'court')));
    }

    public function testAPushOnTheTrackedBranchNamesItsCommit(): void
    {
        self::assertSame(self::COMMIT, GitHubWebhook::pushedCommit($this->push(), 'xdubois-57/secondstay', 'main'));
        // Le nom du dépôt ne dépend pas de la casse chez GitHub.
        self::assertSame(self::COMMIT, GitHubWebhook::pushedCommit($this->push(), 'Xdubois-57/SecondStay', 'main'));
    }

    public function testEverythingElseIsIgnored(): void
    {
        $repository = 'xdubois-57/secondstay';

        self::assertNull(GitHubWebhook::pushedCommit($this->push(), 'quelquun/autre', 'main'));
        self::assertNull(GitHubWebhook::pushedCommit($this->push(), $repository, 'develop'));
        self::assertNull(GitHubWebhook::pushedCommit(['ref' => 'refs/tags/main'] + $this->push(), $repository, 'main'));
        self::assertNull(GitHubWebhook::pushedCommit(['deleted' => true] + $this->push(), $repository, 'main'));
        self::assertNull(GitHubWebhook::pushedCommit(['after' => str_repeat('0', 40)] + $this->push(), $repository, 'main'));
        self::assertNull(GitHubWebhook::pushedCommit(['after' => 'pas-un-sha'] + $this->push(), $repository, 'main'));
        self::assertNull(GitHubWebhook::pushedCommit([], $repository, 'main'));
    }

    /**
     * @return array<string, mixed>
     */
    private function push(): array
    {
        return [
            'ref' => 'refs/heads/main',
            'after' => self::COMMIT,
            'deleted' => false,
            'repository' => ['full_name' => 'xdubois-57/secondstay'],
        ];
    }
}
