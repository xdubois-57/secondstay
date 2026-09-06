<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Core\Router;
use SecondStay\Core\Routes;
use SecondStay\Core\View;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Logging\Logger;
use SecondStay\Mail\FakeMailTransport;
use SecondStay\Mail\MailRepository;
use SecondStay\Mail\MailService;
use SecondStay\Notification\NotificationChannel;
use SecondStay\Notification\NotificationEvent;
use SecondStay\Notification\NotificationPreferenceRepository;
use SecondStay\Notification\NotificationRepository;
use SecondStay\Notification\NotificationService;
use SecondStay\Push\UrlSafeEncoding;
use SecondStay\Push\FakePushProvider;
use SecondStay\Push\PushSubscription;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Push\Vapid;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Notifications : e-mail et push sont indépendants, journalisés séparément et
 * rendus dans la langue du destinataire (ARCHITECTURE.md §14).
 */
final class NotificationServiceTest extends DatabaseTestCase
{
    private NotificationService $notifications;

    private FakeMailTransport $mailTransport;

    private FakePushProvider $push;

    private PushSubscriptionRepository $subscriptions;

    private NotificationRepository $journal;

    private NotificationPreferenceRepository $preferences;

    private UserRepository $users;

    private SettingsService $settings;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->database);
        $this->mailTransport = new FakeMailTransport();
        $this->push = new FakePushProvider('cle-publique-de-test');
        $this->subscriptions = new PushSubscriptionRepository($this->database);
        $this->journal = new NotificationRepository($this->database);
        $this->preferences = new NotificationPreferenceRepository($this->database);

        $router = new Router();
        Routes::register($router);
        $translator = new Translator(self::projectRoot() . '/translations', 'fr');
        $view = new View(self::projectRoot() . '/templates', $translator, new Formatter(), $router);

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $this->settings->setMany([
            'property.name' => 'Maison des Pins',
            'mail.from_address' => 'noreply@example.test',
            'notification.push_enabled' => '1',
        ]);

        $logger = (new Logger($this->storagePath . '/logs'))->withDatabase($this->database);

        $this->notifications = new NotificationService(
            new MailService(
                $this->mailTransport,
                $view,
                $translator,
                $this->settings,
                new MailRepository($this->database),
                $logger,
            ),
            $this->push,
            $this->subscriptions,
            $this->journal,
            $this->preferences,
            $translator,
            $this->settings,
            $logger,
        );

        $this->user = $this->createUser('claire@example.test', 'fr');
    }

    private function createUser(string $email, string $locale): User
    {
        $id = $this->users->create(
            $email,
            self::passwordHash('Marée-Haute-2026!'),
            'Claire',
            'Dubois',
            '',
            Role::Customer,
            $locale,
            UserStatus::Active,
        );

        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user;
    }

    private function subscribe(User $user, string $endpoint = 'https://push.example.test/s/1'): PushSubscription
    {
        $browser = Vapid::generateKeyPair();
        $subscription = new PushSubscription(
            $endpoint,
            $browser['public'],
            UrlSafeEncoding::encode(random_bytes(16)),
            $user->id,
            $user->locale,
        );
        $id = $this->subscriptions->save($subscription, 'PHPUnit');

        return new PushSubscription(
            $subscription->endpoint,
            $subscription->publicKey,
            $subscription->authSecret,
            $user->id,
            $user->locale,
            $id,
        );
    }

    // --- Envoi sur les deux canaux -----------------------------------------

    public function testBothChannelsAreUsedWhenPushIsActive(): void
    {
        $this->subscribe($this->user);

        $result = $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertTrue($result['email']);
        self::assertSame(1, $result['push']);

        self::assertCount(1, $this->mailTransport->messages());
        self::assertCount(1, $this->push->sent());

        // Une ligne de journal par canal.
        $entries = $this->journal->forEvent(NotificationEvent::AccountConfirmed);
        self::assertCount(2, $entries);
        self::assertSame(['email', 'push'], array_column($entries, 'channel'));
        self::assertSame(['sent', 'sent'], array_column($entries, 'status'));
    }

    public function testEmailIsStillSentWithoutAnySubscribedDevice(): void
    {
        $result = $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertTrue($result['email']);
        self::assertSame(0, $result['push']);
        self::assertCount(1, $this->mailTransport->messages());
        self::assertSame([], $this->push->sent());
    }

    public function testAFailingPushDoesNotPreventTheEmail(): void
    {
        $this->subscribe($this->user);
        $this->push->shouldFail = true;

        $result = $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertTrue($result['email']);
        self::assertSame(0, $result['push']);

        $statuses = array_column($this->journal->forEvent(NotificationEvent::AccountConfirmed), 'status', 'channel');
        self::assertSame('sent', $statuses['email']);
        self::assertSame('failed', $statuses['push']);
    }

    public function testAFailingEmailDoesNotPreventThePush(): void
    {
        $this->subscribe($this->user);
        $this->mailTransport->shouldFail = true;

        $result = $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertFalse($result['email']);
        self::assertSame(1, $result['push']);

        $statuses = array_column($this->journal->forEvent(NotificationEvent::AccountConfirmed), 'status', 'channel');
        self::assertSame('failed', $statuses['email']);
        self::assertSame('sent', $statuses['push']);
    }

    public function testEveryDeviceOfTheAccountIsNotified(): void
    {
        $this->subscribe($this->user, 'https://push.example.test/s/1');
        $this->subscribe($this->user, 'https://push.example.test/s/2');
        $this->subscribe($this->createUser('paul@example.test', 'fr'), 'https://push.example.test/s/3');

        $result = $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertSame(2, $result['push']);
        $endpoints = array_column($this->push->sent(), 'endpoint');
        self::assertNotContains('https://push.example.test/s/3', $endpoints);
    }

    // --- Langue -------------------------------------------------------------

    public function testTheMessageFollowsTheLanguageOfTheAccountNotOfTheRequest(): void
    {
        $german = $this->createUser('klaus@example.test', 'de');
        $this->subscribe($german, 'https://push.example.test/s/de');

        $this->notifications->notify(NotificationEvent::AccountConfirmed, $german);

        $mail = $this->mailTransport->lastMessage();
        self::assertNotNull($mail);
        self::assertSame('de', $mail->locale);
        self::assertSame('Ihr Konto ist aktiv', $mail->subject);

        $pushed = $this->push->sent()[0]['message'];
        self::assertSame('de', $pushed['locale']);
        self::assertStringContainsString('Willkommen', $pushed['title']);
        self::assertStringContainsString('Maison des Pins', $pushed['body']);
    }

    public function testEveryLocaleProducesADistinctTranslatedNotification(): void
    {
        $expected = [
            'fr' => 'Votre compte est actif',
            'en' => 'Your account is active',
            'nl' => 'Uw account is actief',
            'de' => 'Ihr Konto ist aktiv',
        ];

        foreach ($expected as $locale => $subject) {
            $user = $this->createUser('client-' . $locale . '@example.test', $locale);
            $this->subscribe($user, 'https://push.example.test/s/' . $locale);
            $this->mailTransport->clear();
            $this->push->clear();

            $this->notifications->notify(NotificationEvent::AccountConfirmed, $user);

            $mail = $this->mailTransport->lastMessage();
            self::assertNotNull($mail);
            self::assertSame($subject, $mail->subject, $locale);
            // Aucune clé de traduction ne fuit dans le rendu.
            self::assertStringNotContainsString('notification.', $mail->html, $locale);
            self::assertStringNotContainsString('{first_name}', $mail->html, $locale);

            self::assertSame($locale, $this->push->sent()[0]['message']['locale']);
        }
    }

    public function testAnUnsupportedAccountLocaleFallsBackWithoutBreaking(): void
    {
        $user = $this->createUser('ana@example.test', 'fr');
        $this->database->update('user', ['locale' => 'es'], ['id' => $user->id]);
        $refreshed = $this->users->findById($user->id);
        self::assertNotNull($refreshed);

        $this->notifications->notify(NotificationEvent::AccountConfirmed, $refreshed);

        $mail = $this->mailTransport->lastMessage();
        self::assertNotNull($mail);
        self::assertSame('fr', $mail->locale);
    }

    // --- Préférences --------------------------------------------------------

    public function testAChannelIsSkippedAndTracedWhenTheAccountDisabledIt(): void
    {
        $this->subscribe($this->user);
        $this->preferences->set($this->user->id, NotificationChannel::Email, false);

        $result = $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertFalse($result['email']);
        self::assertSame(1, $result['push']);
        self::assertSame([], $this->mailTransport->messages());

        $statuses = array_column($this->journal->forEvent(NotificationEvent::AccountConfirmed), 'status', 'channel');
        self::assertSame('skipped', $statuses['email']);
    }

    public function testDisablingPushLeavesTheEmailUntouched(): void
    {
        $this->subscribe($this->user);
        $this->preferences->set($this->user->id, NotificationChannel::Push, false);

        $result = $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertTrue($result['email']);
        self::assertSame(0, $result['push']);
        self::assertSame([], $this->push->sent());
    }

    public function testAnAccountWithoutStoredPreferencesReceivesEverything(): void
    {
        self::assertSame(
            ['email' => true, 'push' => true],
            $this->preferences->forUser($this->user->id)
        );
    }

    public function testPushIsInactiveUntilTheOwnerEnablesIt(): void
    {
        $this->settings->setMany(['notification.push_enabled' => '0']);
        $this->subscribe($this->user);

        self::assertFalse($this->notifications->isPushEnabled());
        self::assertSame('', $this->notifications->pushPublicKey());

        $result = $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertTrue($result['email']);
        self::assertSame(0, $result['push']);
    }

    public function testPushIsInactiveWithoutVapidKeys(): void
    {
        $withoutKeys = new NotificationService(
            new MailService(
                $this->mailTransport,
                new View(
                    self::projectRoot() . '/templates',
                    new Translator(self::projectRoot() . '/translations'),
                    new Formatter(),
                    new Router()
                ),
                new Translator(self::projectRoot() . '/translations'),
                $this->settings,
                new MailRepository($this->database),
                new Logger($this->storagePath . '/logs'),
            ),
            new FakePushProvider(''),
            $this->subscriptions,
            $this->journal,
            $this->preferences,
            new Translator(self::projectRoot() . '/translations'),
            $this->settings,
            new Logger($this->storagePath . '/logs'),
        );

        self::assertFalse($withoutKeys->isPushEnabled());
    }

    // --- Cycle de vie des abonnements ---------------------------------------

    public function testAnExpiredSubscriptionIsRemovedRatherThanRetried(): void
    {
        $this->subscribe($this->user);
        $this->push->shouldExpire = true;

        $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertSame([], $this->subscriptions->forUser($this->user->id));
    }

    public function testASubscriptionSurvivesAnOccasionalFailureButNotAPersistentOne(): void
    {
        $this->subscribe($this->user);
        $this->push->shouldFail = true;

        for ($attempt = 0; $attempt < PushSubscriptionRepository::MAX_FAILURES - 1; $attempt++) {
            $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);
        }
        self::assertCount(1, $this->subscriptions->forUser($this->user->id));

        $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);
        self::assertSame([], $this->subscriptions->forUser($this->user->id));
    }

    public function testASuccessfulDeliveryClearsThePreviousFailures(): void
    {
        $subscription = $this->subscribe($this->user);
        $this->push->shouldFail = true;
        $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        $this->push->shouldFail = false;
        $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        $row = $this->subscriptions->findByEndpointHash($subscription->endpointHash());
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['failures']);
        self::assertNotNull($row['last_used_at']);
    }

    // --- Journal ------------------------------------------------------------

    public function testTheJournalNeverStoresTheMessageBody(): void
    {
        $this->subscribe($this->user);
        $this->notifications->notify(
            NotificationEvent::AccountConfirmed,
            $this->user,
            ['action_path' => '/fr/account'],
            'user:' . $this->user->id,
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->fetchAll('SELECT * FROM `notification`');
        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame('user:' . $this->user->id, $row['reference']);
            self::assertSame($this->user->id, (int) $row['user_id']);
            self::assertSame('fr', $row['locale']);
            self::assertArrayNotHasKey('body', $row);
            self::assertArrayNotHasKey('html', $row);
        }
    }

    public function testTheJournalIsPurgeable(): void
    {
        $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);
        $this->database->execute(
            'UPDATE `notification` SET `created_at` = :old',
            ['old' => gmdate('Y-m-d H:i:s', time() - 400 * 86400)]
        );

        self::assertSame(1, $this->journal->purgeOlderThan(180));
        self::assertSame([], $this->journal->recent());
    }

    public function testTheJournalKeepsRecentEntries(): void
    {
        $this->notifications->notify(NotificationEvent::AccountConfirmed, $this->user);

        self::assertSame(0, $this->journal->purgeOlderThan(180));
        self::assertCount(1, $this->journal->recent());
    }
}
