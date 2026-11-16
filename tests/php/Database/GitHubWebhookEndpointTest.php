<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Core\Http\Request;
use SecondStay\Core\Http\Response;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\InstalledAppTestCase;
use SecondStay\Update\DevUpdateQueue;
use SecondStay\Update\FakeReleaseProvider;
use SecondStay\Update\ReleaseProvider;
use SecondStay\Update\UpdateService;

/**
 * Le point d'entrée des notifications GitHub du canal de développement
 * (ARCHITECTURE.md §30.6.3, SECURITY.md).
 *
 * Il est public, sans session et sans jeton CSRF : la signature HMAC est sa
 * seule authentification. Ce qui est vérifié ici est d'abord ce qu'il
 * refuse.
 */
final class GitHubWebhookEndpointTest extends InstalledAppTestCase
{
    private const SECRET = 'secret-de-test-suffisamment-long-pour-signer-des-notifications';

    private const COMMIT = '0123456789abcdef0123456789abcdef01234567';

    protected function setUp(): void
    {
        parent::setUp();

        $this->container->set(
            ReleaseProvider::class,
            static fn (): ReleaseProvider => new FakeReleaseProvider()
        );
        $this->container->forget(UpdateService::class);
    }

    public function testWithoutASecretEveryNotificationIsRefused(): void
    {
        $response = $this->deliver('ping', ['zen' => 'x'], 'sha256=' . str_repeat('0', 64));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('not_configured', $response->content());
    }

    public function testAWrongSignatureIsRefusedAndNothingIsQueued(): void
    {
        $this->configure('dev');

        $body = (string) json_encode($this->push());
        $forged = 'sha256=' . hash_hmac('sha256', $body, 'un-autre-secret-un-autre-secret-un-autre');

        $response = $this->deliver('push', $this->push(), $forged);

        self::assertSame(403, $response->status());
        self::assertNull($this->queue()->pending());
    }

    public function testAMissingSignatureIsRefused(): void
    {
        $this->configure('dev');

        self::assertSame(403, $this->deliver('push', $this->push(), null)->status());
        self::assertNull($this->queue()->pending());
    }

    public function testThePingOfANewWebhookIsAcknowledged(): void
    {
        $this->configure('dev');

        $response = $this->deliver('ping', ['zen' => 'Keep it logically awesome.']);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('pong', $response->content());
        self::assertSame('pong', $this->queue()->lastDelivery()['outcome'] ?? null);
    }

    public function testAPushOnTheTrackedBranchIsQueued(): void
    {
        $this->configure('dev');

        $response = $this->deliver('push', $this->push());

        self::assertSame(202, $response->status());
        self::assertSame(self::COMMIT, $this->queue()->pending()['commit'] ?? null);
        self::assertSame('queued', $this->queue()->lastDelivery()['outcome'] ?? null);
    }

    public function testAPushIsIgnoredOutsideTheDevelopmentChannel(): void
    {
        $this->configure('stable');

        $response = $this->deliver('push', $this->push());

        self::assertSame(200, $response->status());
        self::assertStringContainsString('ignored_channel', $response->content());
        self::assertNull($this->queue()->pending());
    }

    public function testAPushOnAnotherBranchOrRepositoryIsIgnored(): void
    {
        $this->configure('dev');

        $otherBranch = ['ref' => 'refs/heads/feature'] + $this->push();
        $otherRepository = ['repository' => ['full_name' => 'quelquun/fourche']] + $this->push();

        self::assertSame(200, $this->deliver('push', $otherBranch)->status());
        self::assertSame(200, $this->deliver('push', $otherRepository)->status());
        self::assertNull($this->queue()->pending());
    }

    public function testOtherEventsAreAcknowledgedAndIgnored(): void
    {
        $this->configure('dev');

        $response = $this->deliver('issues', ['action' => 'opened']);

        self::assertSame(200, $response->status());
        self::assertNull($this->queue()->pending());
    }

    /**
     * Le secret est montré une fois, dans la réponse qui le crée, puis n'est
     * plus lisible : il est chiffré en base et la page ne le rend plus.
     */
    public function testTheGeneratedSecretIsShownOnceAndStoredEncrypted(): void
    {
        $this->loginAs();
        $this->settings()->setMany(['update.channel' => 'dev']);

        $response = $this->request('/fr/admin/updates/webhook-secret', 'POST', $this->withCsrf([]));

        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->headers()['cache-control'] ?? null);
        $matches = [];
        preg_match('/data-testid="webhook-secret-value">([0-9a-f]{64})</', $response->content(), $matches);
        $secret = $matches[1] ?? '';
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret, 'Le secret généré doit être montré.');

        $stored = $this->database->fetchValue(
            'SELECT `value` FROM `setting` WHERE `key` = :key',
            ['key' => 'update.webhook_secret']
        );
        self::assertIsString($stored);
        self::assertStringNotContainsString($secret, $stored);

        $page = $this->request('/fr/admin/updates');
        self::assertStringNotContainsString($secret, $page->content());
        self::assertStringContainsString('data-defined="true"', $page->content());

        // Et c'est bien lui qui authentifie désormais les notifications.
        $body = (string) json_encode(['zen' => 'x']);
        $signed = $this->kernel->handle(new Request('POST', '/webhook/github', [], [], [
            'HTTP_X_GITHUB_EVENT' => 'ping',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], [], [], $body));
        self::assertSame(200, $signed->status());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function deliver(string $event, array $payload, ?string $signature = ''): Response
    {
        $body = (string) json_encode($payload);
        $server = [
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => 'livraison-de-test',
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($signature !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature !== ''
                ? $signature
                : 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
        }

        return $this->kernel->handle(new Request('POST', '/webhook/github', [], [], $server, [], [], $body));
    }

    private function configure(string $channel): void
    {
        $this->settings()->setMany([
            'update.channel' => $channel,
            'update.webhook_secret' => self::SECRET,
        ]);
    }

    private function settings(): SettingsService
    {
        return new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            $this->container->get(Encryptor::class),
        );
    }

    private function queue(): DevUpdateQueue
    {
        return $this->container->get(DevUpdateQueue::class);
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
