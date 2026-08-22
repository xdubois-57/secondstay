<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
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
use SecondStay\Notification\StayReminderService;
use SecondStay\Push\FakePushProvider;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Rappels de séjour, arrivées et départs (SPECIFICATIONS.md §42).
 *
 * Ces trois événements savaient se mettre en forme dans quatre langues, mais
 * rien ne les déclenchait avant le planificateur. Ce qui est vérifié ici, ce
 * n'est donc pas leur rendu — c'est **quand** ils partent, et surtout combien
 * de fois : un cron qui repasse toutes les dix minutes ne doit pas produire
 * une rafale de rappels.
 */
final class StayReminderServiceTest extends DatabaseTestCase
{
    private StayReminderService $reminders;

    private FakeMailTransport $mail;

    private NotificationRepository $journal;

    private UserRepository $users;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->database);
        $this->mail = new FakeMailTransport();
        $this->journal = new NotificationRepository($this->database);

        $router = new Router();
        Routes::register($router);
        $translator = new Translator(self::projectRoot() . '/translations', 'fr');
        $view = new View(self::projectRoot() . '/templates', $translator, new Formatter(), $router);

        $settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $settings->setMany([
            'property.name' => 'Maison des Pins',
            'mail.from_address' => 'noreply@example.test',
        ]);

        $logger = (new Logger($this->storagePath . '/logs'))->withDatabase($this->database);

        $notifications = new NotificationService(
            new MailService($this->mail, $view, $translator, $settings, new MailRepository($this->database), $logger),
            new FakePushProvider(''),
            new PushSubscriptionRepository($this->database),
            $this->journal,
            new NotificationPreferenceRepository($this->database),
            $translator,
            $settings,
            $logger,
        );

        $this->reminders = new StayReminderService(
            new BookingRepository($this->database),
            $this->users,
            $notifications,
            $this->journal,
            $settings,
        );

        $this->client = $this->createUser('claire@example.test');
    }

    public function testTheReminderGoesOutExactlyOnceForTheConfiguredLeadTime(): void
    {
        // Rappel par défaut : sept jours avant l'arrivée.
        $this->booking('2026-07-15', '2026-07-22');

        $first = $this->reminders->dispatch('2026-07-08');
        self::assertSame(1, $first['reminders']);

        // Le cron repasse dans la journée : rien ne doit repartir.
        $second = $this->reminders->dispatch('2026-07-08');
        self::assertSame(0, $second['reminders']);

        self::assertCount(1, $this->mail->messages());
    }

    public function testNothingIsSentOutsideTheReminderDay(): void
    {
        $this->booking('2026-07-15', '2026-07-22');

        self::assertSame(0, $this->reminders->dispatch('2026-07-07')['reminders']);
        self::assertSame(0, $this->reminders->dispatch('2026-07-09')['reminders']);
    }

    /**
     * Un cron qui n'a pas tourné pendant une semaine ne doit pas réveiller
     * sept jours de rappels d'un coup : le voyageur recevrait une rafale pour
     * des dates déjà passées.
     */
    public function testMissedDaysAreNotReplayed(): void
    {
        $this->booking('2026-07-15', '2026-07-22');

        // Le cron reprend trois jours trop tard : la date de rappel est
        // passée, le rappel ne part plus.
        self::assertSame(0, $this->reminders->dispatch('2026-07-11')['reminders']);
    }

    public function testArrivalsAndDeparturesFollowTheirOwnDates(): void
    {
        $this->booking('2026-07-15', '2026-07-22');

        $arrival = $this->reminders->dispatch('2026-07-15');
        self::assertSame(1, $arrival['arrivals']);
        self::assertSame(0, $arrival['departures']);

        $departure = $this->reminders->dispatch('2026-07-22');
        self::assertSame(0, $departure['arrivals']);
        self::assertSame(1, $departure['departures']);

        self::assertTrue($this->journal->hasBeenSent(NotificationEvent::Arrival, 'arrival:1'));
    }

    public function testACancelledStayIsNeverAnnounced(): void
    {
        $this->booking('2026-07-15', '2026-07-22', BookingStatus::Cancelled);

        $result = $this->reminders->dispatch('2026-07-15');

        self::assertSame(0, $result['arrivals']);
        self::assertSame(0, $result['departures']);
        self::assertSame([], $this->mail->messages());
    }

    /**
     * Un séjour saisi depuis l'administration pour quelqu'un qui n'a pas de
     * compte n'a pas de destinataire dont on connaisse les préférences de
     * canal. Le compter comme envoyé serait faux.
     */
    public function testAStayWithoutAccountIsCountedAsSkippedNotSent(): void
    {
        $this->database->insert('booking', [
            'reference' => 'AAAA-BBBB',
            'user_id' => null,
            'status' => BookingStatus::Confirmed->value,
            'arrival' => '2026-07-15',
            'departure' => '2026-07-22',
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => 'inconnu@example.test',
            'guest_name' => 'Voyageur',
            'guest_phone' => '',
            'total_cents' => 90_000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = $this->reminders->dispatch('2026-07-15');

        self::assertSame(0, $result['arrivals']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $this->mail->messages());
    }

    /**
     * Le même séjour saisi sans compte mais avec l'adresse d'un client connu
     * doit bien atteindre ce client : l'absence de `user_id` est une lacune de
     * saisie, pas une volonté de ne pas prévenir.
     */
    public function testAKnownEmailIsEnoughToReachTheGuest(): void
    {
        $this->database->insert('booking', [
            'reference' => 'CCCC-DDDD',
            'user_id' => null,
            'status' => BookingStatus::Confirmed->value,
            'arrival' => '2026-07-15',
            'departure' => '2026-07-22',
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => 'claire@example.test',
            'guest_name' => 'Claire Dubois',
            'guest_phone' => '',
            'total_cents' => 90_000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        self::assertSame(1, $this->reminders->dispatch('2026-07-15')['arrivals']);
    }

    /**
     * Une panne de courrier ne doit pas **consommer** le rappel.
     *
     * C'est le pire des deux mondes : le voyageur ne reçoit rien, et le
     * propriétaire ne peut rien y faire — relancer la tâche une fois le
     * serveur rétabli ne renverrait pas un rappel réputé parti. Une tentative
     * en échec n'est pas une décision, c'est un incident : elle se rejoue.
     */
    public function testAFailedSendIsRetriedOnceTheMailServerIsBack(): void
    {
        $this->booking('2026-07-15', '2026-07-22');

        $this->mail->shouldFail = true;
        self::assertSame(1, $this->reminders->dispatch('2026-07-15')['arrivals']);
        self::assertSame([], $this->mail->messages(), 'Rien n’est réellement parti.');
        self::assertFalse($this->journal->hasBeenSent(NotificationEvent::Arrival, 'arrival:1'));

        // Le serveur de courrier revient, le propriétaire relance la tâche.
        $this->mail->shouldFail = false;
        $this->reminders->dispatch('2026-07-15');

        self::assertCount(1, $this->mail->messages());
        self::assertTrue($this->journal->hasBeenSent(NotificationEvent::Arrival, 'arrival:1'));

        // Et une fois parti, il ne repart pas.
        $this->reminders->dispatch('2026-07-15');
        self::assertCount(1, $this->mail->messages());
    }

    /**
     * Un canal volontairement désactivé, lui, compte comme traité : ce n'est
     * pas un incident, c'est un choix du voyageur, et le réessayer chaque nuit
     * ne changerait rien.
     */
    public function testADeliberatelyDisabledChannelIsNotRetried(): void
    {
        $this->booking('2026-07-15', '2026-07-22');

        (new NotificationPreferenceRepository($this->database))
            ->set($this->client->id, NotificationChannel::Email, false);

        $this->reminders->dispatch('2026-07-15');

        self::assertSame([], $this->mail->messages());
        self::assertTrue($this->journal->hasBeenSent(NotificationEvent::Arrival, 'arrival:1'));
    }

    /**
     * Une demande encore en attente de réponse n'est pas un séjour : écrire
     * « votre séjour commence dans sept jours » engagerait le propriétaire à
     * sa place.
     */
    public function testAnUnconfirmedRequestIsNeverReminded(): void
    {
        $this->booking('2026-07-15', '2026-07-22', BookingStatus::ToConfirm);

        self::assertSame(0, $this->reminders->dispatch('2026-07-08')['reminders']);
        self::assertSame([], $this->mail->messages());
    }

    public function testTheLeadTimeStaysWithinItsBounds(): void
    {
        self::assertSame(7, $this->reminders->reminderDays());
    }

    private function createUser(string $email): User
    {
        $id = $this->users->create(
            $email,
            self::passwordHash('Marée-Haute-2026!'),
            'Claire',
            'Dubois',
            '+33600000000',
            Role::Customer,
            'fr',
            UserStatus::Active,
        );

        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user;
    }

    private function booking(
        string $arrival,
        string $departure,
        BookingStatus $status = BookingStatus::Confirmed,
    ): int {
        return $this->database->insert('booking', [
            'reference' => 'ABCD-EFGH',
            'user_id' => $this->client->id,
            'status' => $status->value,
            'arrival' => $arrival,
            'departure' => $departure,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => $this->client->email,
            'guest_name' => 'Claire Dubois',
            'guest_phone' => '+33600000000',
            'total_cents' => 90_000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
