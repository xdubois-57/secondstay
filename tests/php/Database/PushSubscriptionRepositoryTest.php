<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Auth\Role;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Push\Base64Url;
use SecondStay\Push\PushSubscription;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Push\Vapid;
use SecondStay\Push\VapidKeyManager;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

final class PushSubscriptionRepositoryTest extends DatabaseTestCase
{
    private PushSubscriptionRepository $repository;

    private UserRepository $users;

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PushSubscriptionRepository($this->database);
        $this->users = new UserRepository($this->database);
        $this->userId = $this->createUser('claire@example.test');
    }

    private function createUser(string $email): int
    {
        return $this->users->create(
            $email,
            self::passwordHash('Marée-Haute-2026!'),
            'Claire',
            'Dubois',
            '',
            Role::Customer,
            'fr',
            UserStatus::Active,
        );
    }

    private function subscription(string $endpoint, int $userId = 0): PushSubscription
    {
        return new PushSubscription(
            $endpoint,
            Vapid::generateKeyPair()['public'],
            Base64Url::encode(random_bytes(16)),
            $userId === 0 ? $this->userId : $userId,
            'fr',
        );
    }

    public function testTheSameEndpointIsNeverStoredTwice(): void
    {
        $endpoint = 'https://push.example.test/s/1';

        $first = $this->repository->save($this->subscription($endpoint), 'Firefox');
        $second = $this->repository->save($this->subscription($endpoint), 'Firefox (mis à jour)');

        self::assertSame($first, $second);
        self::assertSame(1, $this->repository->countForUser($this->userId));

        $row = $this->repository->findByEndpointHash(hash('sha256', $endpoint));
        self::assertNotNull($row);
        self::assertSame('Firefox (mis à jour)', $row['user_agent']);
    }

    public function testARefreshedSubscriptionResetsItsFailureCount(): void
    {
        $endpoint = 'https://push.example.test/s/1';
        $id = $this->repository->save($this->subscription($endpoint));

        $this->repository->markFailed($id);
        $this->repository->markFailed($id);
        $this->repository->save($this->subscription($endpoint));

        $row = $this->repository->findByEndpointHash(hash('sha256', $endpoint));
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['failures']);
    }

    public function testASubscriptionIsDroppedAfterTooManyFailures(): void
    {
        $id = $this->repository->save($this->subscription('https://push.example.test/s/1'));

        for ($attempt = 1; $attempt < PushSubscriptionRepository::MAX_FAILURES; $attempt++) {
            self::assertFalse($this->repository->markFailed($id), 'échec ' . $attempt);
        }

        self::assertTrue($this->repository->markFailed($id));
        self::assertSame(0, $this->repository->countForUser($this->userId));
    }

    public function testSubscriptionsAreScopedToTheirAccount(): void
    {
        $other = $this->createUser('paul@example.test');

        $this->repository->save($this->subscription('https://push.example.test/s/1'));
        $this->repository->save($this->subscription('https://push.example.test/s/2'));
        $this->repository->save($this->subscription('https://push.example.test/s/3', $other));

        self::assertCount(2, $this->repository->forUser($this->userId));
        self::assertCount(1, $this->repository->forUser($other));
        self::assertSame(3, $this->repository->countAll());
    }

    public function testTheStoredRowRoundTripsIntoAUsableSubscription(): void
    {
        $original = $this->subscription('https://push.example.test/s/1');
        $this->repository->save($original);

        $restored = $this->repository->forUser($this->userId)[0];

        self::assertSame($original->endpoint, $restored->endpoint);
        self::assertSame($original->publicKey, $restored->publicKey);
        self::assertSame($original->authSecret, $restored->authSecret);
        self::assertSame(65, strlen($restored->binaryPublicKey()));
        self::assertSame(16, strlen($restored->binaryAuthSecret()));
        self::assertGreaterThan(0, $restored->id);
    }

    public function testDeletingAnAccountRemovesItsSubscriptions(): void
    {
        $this->repository->save($this->subscription('https://push.example.test/s/1'));

        $this->database->delete('user', ['id' => $this->userId]);

        self::assertSame(0, $this->repository->countAll());
    }

    public function testEverySubscriptionCanBeClearedAtOnce(): void
    {
        $this->repository->save($this->subscription('https://push.example.test/s/1'));
        $this->repository->save($this->subscription('https://push.example.test/s/2'));

        self::assertSame(2, $this->repository->clearAll());
        self::assertSame(0, $this->repository->countAll());
    }

    public function testUnsubscribingByEndpointRemovesExactlyOneDevice(): void
    {
        $this->repository->save($this->subscription('https://push.example.test/s/1'));
        $this->repository->save($this->subscription('https://push.example.test/s/2'));

        self::assertTrue($this->repository->deleteByEndpointHash(hash('sha256', 'https://push.example.test/s/1')));
        self::assertFalse($this->repository->deleteByEndpointHash(hash('sha256', 'https://push.example.test/inconnu')));
        self::assertSame(1, $this->repository->countForUser($this->userId));
    }

    // --- Clés VAPID ---------------------------------------------------------

    public function testVapidKeysAreGeneratedOnceAndStoredAsASecret(): void
    {
        $settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $manager = new VapidKeyManager($settings);

        self::assertFalse($manager->hasKeys());

        $public = $manager->ensureKeys();
        self::assertNotSame('', $public);
        self::assertTrue($manager->hasKeys());
        self::assertTrue($manager->vapid()->isUsable());

        // Un second appel ne régénère pas.
        self::assertSame($public, $manager->ensureKeys());

        // La clé privée est chiffrée au repos.
        $stored = (string) $this->database->fetchValue(
            'SELECT `value` FROM `setting` WHERE `key` = :key',
            ['key' => VapidKeyManager::PRIVATE_SETTING]
        );
        self::assertNotSame('', $stored);
        self::assertStringStartsWith('ss1.', $stored);
        self::assertStringNotContainsString((string) $settings->get(VapidKeyManager::PRIVATE_SETTING), $stored);
    }

    public function testRenewingKeysProducesADifferentPair(): void
    {
        $settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $manager = new VapidKeyManager($settings);

        $first = $manager->ensureKeys();
        $second = $manager->ensureKeys(true);

        self::assertNotSame($first, $second);
        self::assertTrue($manager->vapid()->isUsable());
    }

    public function testTheContactFallsBackToTheSenderAddress(): void
    {
        $settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $settings->setMany(['mail.from_address' => 'proprietaire@example.test']);

        $manager = new VapidKeyManager($settings);
        $manager->ensureKeys();

        $header = $manager->vapid()->authorizationHeader('https://push.example.test/s/1');
        [$token] = explode(', k=', substr($header['authorization'], strlen('vapid t=')));
        /** @var array{sub: string} $claims */
        $claims = json_decode(Base64Url::decode(explode('.', $token)[1]), true);

        self::assertSame('mailto:proprietaire@example.test', $claims['sub']);
    }
}
