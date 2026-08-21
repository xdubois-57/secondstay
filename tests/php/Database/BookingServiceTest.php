<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Availability\AvailabilityBlockRepository;
use SecondStay\Availability\AvailabilityService;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingReference;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingService;
use SecondStay\Booking\BookingStatus;
use SecondStay\Booking\PromoCode;
use SecondStay\Booking\PromoCodeRepository;
use SecondStay\Booking\StayRules;
use SecondStay\Booking\WaitlistRepository;
use SecondStay\Database\Database;
use SecondStay\Logging\Logger;
use SecondStay\Mail\FakeMailTransport;
use SecondStay\Mail\MailRepository;
use SecondStay\Mail\MailService;
use SecondStay\Pricing\DateRange;
use SecondStay\Pricing\PriceCalculator;
use SecondStay\Pricing\RateRepository;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Parcours de réservation et **anti-double-réservation**.
 *
 * La garantie ne repose pas sur une vérification suivie d'une écriture : elle
 * repose sur la clé primaire de `booking_night`. Ces tests l'attaquent avec
 * deux connexions réellement concurrentes, pas avec deux appels successifs.
 */
final class BookingServiceTest extends DatabaseTestCase
{
    private BookingService $bookings;

    private BookingRepository $repository;

    private BookingEventRepository $events;

    private PromoCodeRepository $promos;

    private WaitlistRepository $waitlist;

    private AvailabilityBlockRepository $blocks;

    private RateRepository $rates;

    private SettingsService $settings;

    private FakeMailTransport $mailTransport;

    private UserRepository $users;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookings = $this->buildService($this->database);
        $this->users = new UserRepository($this->database);
        $this->client = $this->createClient('claire@example.test');
    }

    private function buildService(Database $database): BookingService
    {
        $this->repository = new BookingRepository($database);
        $this->events = new BookingEventRepository($database);
        $this->promos = new PromoCodeRepository($database);
        $this->waitlist = new WaitlistRepository($database);
        $this->blocks = new AvailabilityBlockRepository($database);
        $this->rates = new RateRepository($database);

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $this->settings->setMany([
            'pricing.default_night_price' => '120,00',
            'pricing.cleaning_price' => '100,00',
            'pricing.cleaning_mode' => 'mandatory',
            'pricing.deposit_percent' => '30',
            'booking.min_nights' => '2',
            'booking.max_guests' => '6',
            'booking.hold_minutes' => '30',
            'booking.requires_approval' => '1',
        ]);

        $prices = new PriceCalculator($this->settings, $this->rates);
        $rules = new StayRules($this->settings, 'Europe/Paris');
        $availability = new AvailabilityService(
            $this->blocks,
            $this->rates,
            $prices,
            $rules,
            'Europe/Paris',
            $this->repository,
        );

        $this->mailTransport = new FakeMailTransport();
        $logger = new Logger($this->storagePath . '/logs');

        return new BookingService(
            $this->repository,
            $this->events,
            $this->promos,
            $this->waitlist,
            $rules,
            $availability,
            $prices,
            $this->settings,
            $logger,
            null,
            new MailService(
                $this->mailTransport,
                $this->view(),
                $this->translator(),
                $this->settings,
                new MailRepository($database),
                $logger,
            ),
            new AuditTrail($database),
        );
    }

    private function view(): \SecondStay\Core\View
    {
        $router = new \SecondStay\Core\Router();
        \SecondStay\Core\Routes::register($router);

        return new \SecondStay\Core\View(
            self::projectRoot() . '/templates',
            $this->translator(),
            new \SecondStay\I18n\Formatter(),
            $router,
        );
    }

    private function translator(): \SecondStay\I18n\Translator
    {
        return new \SecondStay\I18n\Translator(self::projectRoot() . '/translations', 'fr');
    }

    private function createClient(string $email): User
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

    /**
     * @return array{arrival: string, departure: string}
     */
    private function dates(int $offsetDays = 90, int $nights = 7): array
    {
        $arrival = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))
            ->modify('+' . $offsetDays . ' days');

        return [
            'arrival' => $arrival->format('Y-m-d'),
            'departure' => $arrival->modify('+' . $nights . ' days')->format('Y-m-d'),
        ];
    }

    private function hold(int $offsetDays = 90, int $nights = 7, ?User $user = null): Booking
    {
        $result = $this->bookings->hold($this->dates($offsetDays, $nights) + ['adults' => 2], $user ?? $this->client);

        self::assertTrue($result['ok'], (string) json_encode($result));

        return $result['booking'];
    }

    // --- Anti-double-réservation --------------------------------------------

    public function testASecondHoldOnTheSameNightsIsRefused(): void
    {
        $first = $this->hold();

        $second = $this->bookings->hold($this->dates() + ['adults' => 2], $this->createClient('paul@example.test'));

        self::assertFalse($second['ok']);
        self::assertContains('booking.error.unavailable', $second['errors']);
        self::assertNotSame([], $second['conflicts']);

        // Une seule réservation détient les nuits.
        self::assertSame(7, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `booking_night`'));
        self::assertSame(
            $first->id,
            (int) $this->database->fetchValue('SELECT `booking_id` FROM `booking_night` LIMIT 1')
        );
    }

    public function testAnOverlapOfASingleNightIsEnough(): void
    {
        $this->hold(90, 7);

        // Arrivée la veille du départ du premier séjour : une nuit commune.
        $overlap = $this->bookings->hold($this->dates(96, 5) + ['adults' => 2], $this->client);

        self::assertFalse($overlap['ok']);
    }

    public function testAStayStartingTheDayTheOtherLeavesIsAccepted(): void
    {
        $this->hold(90, 7);

        // Départ le jour 97, arrivée le jour 97 : aucune nuit commune.
        $next = $this->bookings->hold($this->dates(97, 4) + ['adults' => 2], $this->client);

        self::assertTrue($next['ok'], (string) json_encode($next));
        self::assertSame(11, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `booking_night`'));
    }

    /**
     * Le scénario critique : deux transactions **réellement concurrentes**.
     *
     * Chacune ouvre sa propre connexion et sa propre transaction, et insère
     * ses nuits avant que l'autre ne valide. Sans la contrainte d'unicité, les
     * deux réussiraient.
     */
    public function testTwoConcurrentTransactionsCannotHoldTheSameNights(): void
    {
        $config = self::databaseConfig();
        self::assertNotNull($config);

        $dates = $this->dates();
        $range = DateRange::fromStrings($dates['arrival'], $dates['departure']);

        $first = new Database($config);
        $second = new Database($config);

        $firstPdo = $first->pdo();
        $secondPdo = $second->pdo();

        // Les deux transactions démarrent avant que l'une n'ait écrit.
        $firstPdo->beginTransaction();
        $secondPdo->beginTransaction();

        $firstId = $this->insertBookingRow($first, 'AAAA-BBBB', $range);
        foreach ($range->nightKeys() as $day) {
            $first->insert('booking_night', ['day' => $day, 'booking_id' => $firstId]);
        }

        $secondId = $this->insertBookingRow($second, 'CCCC-DDDD', $range);

        // La seconde transaction attend le verrou de ligne puis échoue.
        $firstPdo->commit();

        $secondFailed = false;
        try {
            foreach ($range->nightKeys() as $day) {
                $second->insert('booking_night', ['day' => $day, 'booking_id' => $secondId]);
            }
            $secondPdo->commit();
        } catch (\PDOException $exception) {
            $secondFailed = true;
            self::assertSame('23000', $exception->getCode());
            $secondPdo->rollBack();
        }

        self::assertTrue($secondFailed, 'La seconde transaction aurait dû être refusée.');

        // Exactement sept nuits, toutes détenues par la première réservation.
        self::assertSame(7, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `booking_night`'));
        self::assertSame(
            [$firstId],
            array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['booking_id'],
                $this->database->fetchAll('SELECT `booking_id` FROM `booking_night`')
            )))
        );
    }

    private function insertBookingRow(Database $database, string $reference, DateRange $range): int
    {
        return $database->insert('booking', [
            'reference' => $reference,
            'status' => BookingStatus::Hold->value,
            'arrival' => $range->arrivalKey(),
            'departure' => $range->departureKey(),
            'adults' => 2,
            'locale' => 'fr',
            'currency' => 'EUR',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testCancellingFreesTheNightsForSomeoneElse(): void
    {
        $first = $this->hold();

        $cancelled = $this->bookings->transition($first, BookingStatus::Cancelled, $this->client);
        self::assertTrue($cancelled['ok']);

        self::assertSame(0, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `booking_night`'));

        $second = $this->bookings->hold($this->dates() + ['adults' => 2], $this->createClient('paul@example.test'));
        self::assertTrue($second['ok'], (string) json_encode($second));
    }

    public function testABlockedNightPreventsAHold(): void
    {
        $dates = $this->dates();
        $range = DateRange::fromStrings($dates['arrival'], $dates['departure']);
        $this->blocks->create(
            DateRange::fromNights($range->nightKeys()[2], $range->nightKeys()[2]),
            'owner',
            'Séjour propriétaire'
        );

        $result = $this->bookings->hold($dates + ['adults' => 2], $this->client);

        self::assertFalse($result['ok']);
        self::assertSame([$range->nightKeys()[2]], $result['conflicts']);
        // Rien n'a été écrit : pas de réservation fantôme.
        self::assertSame(0, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `booking`'));
    }

    public function testABookedNightAppearsAsUnavailableInTheCalendar(): void
    {
        $booking = $this->hold();

        $prices = new PriceCalculator($this->settings, $this->rates);
        $availability = new AvailabilityService(
            $this->blocks,
            $this->rates,
            $prices,
            new StayRules($this->settings, 'Europe/Paris'),
            'Europe/Paris',
            $this->repository,
        );

        $states = $availability->nightStates($booking->range);

        foreach ($booking->range->nightKeys() as $day) {
            self::assertSame(AvailabilityService::STATE_BLOCKED, $states[$day]['state']);
            // Le calendrier public ne dit jamais qui occupe la nuit.
            self::assertSame(AvailabilityService::REASON_BOOKED, $states[$day]['label']);
        }

        self::assertStringNotContainsString('claire@example.test', json_encode($states) ?: '');
    }

    // --- Verrou temporaire ---------------------------------------------------

    public function testAHoldCarriesAnExpiry(): void
    {
        $booking = $this->hold();

        self::assertSame(BookingStatus::Hold, $booking->status);
        self::assertNotNull($booking->expiresAt);
        self::assertFalse($booking->isExpired());
        self::assertTrue($booking->isExpired(time() + 31 * 60));
    }

    public function testAnExpiredHoldIsReleasedAndItsNightsFreed(): void
    {
        $booking = $this->hold();

        $this->database->update(
            'booking',
            ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)],
            ['id' => $booking->id]
        );

        self::assertSame(1, $this->bookings->releaseExpiredHolds());
        self::assertSame(0, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `booking_night`'));

        $released = $this->repository->find($booking->id);
        self::assertNotNull($released);
        self::assertSame(BookingStatus::Cancelled, $released->status);

        // Les nuits redeviennent réservables.
        self::assertTrue($this->bookings->hold($this->dates() + ['adults' => 2], $this->client)['ok']);
    }

    public function testAValidHoldIsNotReleased(): void
    {
        $this->hold();

        self::assertSame(0, $this->bookings->releaseExpiredHolds());
        self::assertSame(7, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `booking_night`'));
    }

    // --- Workflow -------------------------------------------------------------

    public function testSubmittingAHoldProducesARequestAwaitingApproval(): void
    {
        $booking = $this->hold();

        $result = $this->bookings->submit($booking, ['accept_rules' => '1', 'message' => 'Bonjour'], $this->client);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(BookingStatus::ToConfirm, $result['booking']->status);
        self::assertNull($result['booking']->expiresAt);
        self::assertSame($this->client->id, $result['booking']->userId);
        self::assertSame('claire@example.test', $result['booking']->guestEmail);
    }

    public function testAutomaticConfirmationIsOptIn(): void
    {
        $this->settings->setMany(['booking.requires_approval' => '0']);

        $result = $this->bookings->submit($this->hold(), ['accept_rules' => '1'], $this->client);

        self::assertTrue($result['ok']);
        self::assertSame(BookingStatus::Confirmed, $result['booking']->status);
        self::assertNotNull($result['booking']->confirmedAt);
    }

    public function testTheRulesMustBeAccepted(): void
    {
        $result = $this->bookings->submit($this->hold(), [], $this->client);

        self::assertFalse($result['ok']);
        self::assertContains('booking.error.rules_required', $result['errors']);
    }

    public function testAnExpiredHoldCannotBeSubmitted(): void
    {
        $booking = $this->hold();
        $this->database->update(
            'booking',
            ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)],
            ['id' => $booking->id]
        );

        $expired = $this->repository->find($booking->id);
        self::assertNotNull($expired);

        $result = $this->bookings->submit($expired, ['accept_rules' => '1'], $this->client);

        self::assertFalse($result['ok']);
        self::assertContains('booking.error.hold_expired', $result['errors']);
    }

    /**
     * @return list<array{string, string, bool}>
     */
    public static function transitions(): array
    {
        return [
            ['hold', 'request', true],
            ['hold', 'confirmed', false],
            ['request', 'to_confirm', true],
            ['request', 'confirmed', true],
            ['to_confirm', 'confirmed', true],
            ['to_confirm', 'refused', true],
            ['confirmed', 'in_progress', true],
            ['confirmed', 'completed', true],
            ['confirmed', 'to_confirm', false],
            ['completed', 'cancelled', false],
            ['cancelled', 'confirmed', false],
            ['refused', 'confirmed', false],
        ];
    }

    #[DataProvider('transitions')]
    public function testOnlyDeclaredTransitionsAreAllowed(string $from, string $to, bool $expected): void
    {
        self::assertSame(
            $expected,
            BookingStatus::from($from)->canTransitionTo(BookingStatus::from($to))
        );
    }

    public function testAnUndeclaredTransitionIsRefusedByTheService(): void
    {
        $booking = $this->hold();

        $result = $this->bookings->transition($booking, BookingStatus::Completed, $this->client);

        self::assertFalse($result['ok']);
        self::assertContains('booking.error.transition', $result['errors']);

        $unchanged = $this->repository->find($booking->id);
        self::assertNotNull($unchanged);
        self::assertSame(BookingStatus::Hold, $unchanged->status);
    }

    public function testARefusedStayFreesItsNights(): void
    {
        $booking = $this->hold();
        $submitted = $this->bookings->submit($booking, ['accept_rules' => '1'], $this->client);
        self::assertTrue($submitted['ok']);

        $refused = $this->bookings->transition($submitted['booking'], BookingStatus::Refused, $this->client, 'Complet');

        self::assertTrue($refused['ok']);
        self::assertSame(0, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `booking_night`'));
        self::assertNotNull($refused['booking']->cancelledAt);
    }

    // --- Timeline -------------------------------------------------------------

    public function testEveryImportantStepLandsInTheTimeline(): void
    {
        $booking = $this->hold();
        $submitted = $this->bookings->submit($booking, ['accept_rules' => '1'], $this->client);
        self::assertTrue($submitted['ok']);
        $this->bookings->transition($submitted['booking'], BookingStatus::Confirmed, $this->client);

        $types = array_column($this->events->forBooking($booking->id), 'type');

        self::assertSame(['hold_created', 'requested', 'status_confirmed'], $types);

        $timeline = $this->events->forBooking($booking->id);
        self::assertSame('claire@example.test', $timeline[1]['actor_label']);
        self::assertSame(7, $timeline[0]['data']['nights']);
    }

    // --- Montants ------------------------------------------------------------

    public function testTheStoredAmountsComeFromTheServerNotTheForm(): void
    {
        $dates = $this->dates();
        $range = DateRange::fromStrings($dates['arrival'], $dates['departure']);
        $this->rates->applyToRange(
            DateRange::fromNights($range->nightKeys()[0], $range->nightKeys()[2]),
            25000
        );

        $result = $this->bookings->hold($dates + [
            'adults' => 2,
            // Un formulaire malveillant tente d'imposer son propre total.
            'total_cents' => 1,
            'accommodation_cents' => 1,
            'deposit_cents' => 0,
        ], $this->client);

        self::assertTrue($result['ok']);
        $booking = $result['booking'];

        self::assertSame(3 * 25000 + 4 * 12000, $booking->accommodationCents);
        self::assertSame(10000, $booking->cleaningCents);
        self::assertSame(3 * 25000 + 4 * 12000 + 10000, $booking->totalCents);
        self::assertSame((int) ceil($booking->totalCents * 0.30), $booking->depositCents);
        self::assertSame($booking->totalCents - $booking->depositCents, $booking->balanceCents());
    }

    public function testAmountsAreFrozenWhenTheRateChangesLater(): void
    {
        $booking = $this->hold();
        $initial = $booking->totalCents;

        $this->rates->applyToRange($booking->range, 90000);

        $stored = $this->repository->find($booking->id);
        self::assertNotNull($stored);
        self::assertSame($initial, $stored->totalCents);
    }

    // --- Codes promo -----------------------------------------------------------

    public function testAPercentCodeReducesTheAccommodationOnly(): void
    {
        $this->promos->create('ETE-10', PromoCode::KIND_PERCENT, 10);

        $result = $this->bookings->hold(
            $this->dates() + ['adults' => 2, 'promo_code' => 'ete-10'],
            $this->client
        );

        self::assertTrue($result['ok']);
        $booking = $result['booking'];

        $accommodation = 7 * 12000;
        self::assertSame('ETE-10', $booking->promoCode);
        self::assertSame((int) floor($accommodation * 0.10), $booking->discountCents);
        // Le ménage n'est jamais remisé.
        self::assertSame($accommodation + 10000 - $booking->discountCents, $booking->totalCents);
    }

    public function testAFixedCodeCannotProduceANegativeTotal(): void
    {
        $this->promos->create('CADEAU', PromoCode::KIND_FIXED, 999_999_00);

        $result = $this->bookings->hold(
            $this->dates() + ['adults' => 2, 'promo_code' => 'CADEAU'],
            $this->client
        );

        self::assertTrue($result['ok']);
        // La remise est bornée à l'hébergement : le ménage reste dû.
        self::assertSame(7 * 12000, $result['booking']->discountCents);
        self::assertSame(10000, $result['booking']->totalCents);
    }

    /**
     * @return list<array{array<string, mixed>, string}>
     */
    public static function refusedCodes(): array
    {
        return [
            [['is_active' => 0], 'booking.promo.inactive'],
            [['ends_on' => '2000-01-01'], 'booking.promo.expired'],
            [['starts_on' => '2999-01-01'], 'booking.promo.not_started'],
            [['max_uses' => 1, 'used_count' => 1], 'booking.promo.exhausted'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('refusedCodes')]
    public function testAnUnusableCodeIsIgnoredWithoutBlockingTheBooking(array $overrides, string $reason): void
    {
        $id = $this->promos->create('PROMO', PromoCode::KIND_PERCENT, 10);
        $this->database->update('promo_code', $overrides, ['id' => $id]);

        $promo = $this->promos->find('PROMO');
        self::assertNotNull($promo);
        self::assertSame($reason, $promo->refusalReason(gmdate('Y-m-d')));

        $result = $this->bookings->hold(
            $this->dates() + ['adults' => 2, 'promo_code' => 'PROMO'],
            $this->client
        );

        // Le séjour reste réservable, simplement sans remise.
        self::assertTrue($result['ok']);
        self::assertSame('', $result['booking']->promoCode);
        self::assertSame(0, $result['booking']->discountCents);
    }

    public function testAnUnknownCodeIsIgnored(): void
    {
        $result = $this->bookings->hold(
            $this->dates() + ['adults' => 2, 'promo_code' => 'INCONNU'],
            $this->client
        );

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['booking']->discountCents);
    }

    public function testACodeIsConsumedOnlyWhenTheRequestIsSubmitted(): void
    {
        $id = $this->promos->create('LIMITE', PromoCode::KIND_PERCENT, 10, null, null, 1);

        $booking = $this->hold();
        $this->database->update('booking', ['promo_code' => 'LIMITE'], ['id' => $booking->id]);

        $promo = $this->promos->find('LIMITE');
        self::assertNotNull($promo);
        self::assertSame(0, $promo->usedCount);

        $stored = $this->repository->find($booking->id);
        self::assertNotNull($stored);
        $this->bookings->submit($stored, ['accept_rules' => '1'], $this->client);

        $consumed = $this->promos->find('LIMITE');
        self::assertNotNull($consumed);
        self::assertSame(1, $consumed->usedCount);

        // La limite est respectée même sous concurrence.
        self::assertFalse($this->promos->consume($id));
    }

    // --- Liste d'attente -------------------------------------------------------

    public function testJoiningTheWaitlistIsIdempotent(): void
    {
        $dates = $this->dates();
        $range = DateRange::fromStrings($dates['arrival'], $dates['departure']);

        self::assertTrue($this->bookings->joinWaitlist($range, 'Paul@Example.test', 'nl')['ok']);
        self::assertTrue($this->bookings->joinWaitlist($range, 'paul@example.test', 'nl')['ok']);

        self::assertSame(1, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `waitlist_entry`'));
    }

    public function testTheWaitlistRefusesAnInvalidAddress(): void
    {
        $dates = $this->dates();

        $result = $this->bookings->joinWaitlist(
            DateRange::fromStrings($dates['arrival'], $dates['departure']),
            'pas-une-adresse',
            'fr'
        );

        self::assertFalse($result['ok']);
        self::assertSame('account.error.email_invalid', $result['error']);
    }

    public function testFreedNightsNotifyTheWaitlistOnceInItsOwnLanguage(): void
    {
        $dates = $this->dates();
        $range = DateRange::fromStrings($dates['arrival'], $dates['departure']);

        $booking = $this->hold();
        $this->bookings->joinWaitlist($range, 'paul@example.test', 'de');
        $this->mailTransport->clear();

        $this->bookings->transition($booking, BookingStatus::Cancelled, $this->client);

        $message = $this->mailTransport->lastMessage();
        self::assertNotNull($message);
        self::assertSame('waitlist_available', $message->template);
        self::assertSame('de', $message->locale);
        self::assertSame('paul@example.test', $message->to->address);

        // Une inscription n'est prévenue qu'une fois.
        $entry = $this->database->fetchOne('SELECT * FROM `waitlist_entry`');
        self::assertNotNull($entry);
        self::assertNotNull($entry['notified_at']);
    }

    public function testAnUnrelatedWaitlistEntryIsNotNotified(): void
    {
        $booking = $this->hold(90, 7);

        // Une période bien après le séjour libéré.
        $other = $this->dates(300, 7);
        $this->bookings->joinWaitlist(
            DateRange::fromStrings($other['arrival'], $other['departure']),
            'paul@example.test',
            'fr'
        );
        $this->mailTransport->clear();

        $this->bookings->transition($booking, BookingStatus::Cancelled, $this->client);

        self::assertSame([], $this->mailTransport->messages());
    }

    // --- Référence -------------------------------------------------------------

    public function testReferencesAreReadableAndUnique(): void
    {
        $references = [];
        for ($index = 0; $index < 200; $index++) {
            $reference = BookingReference::generate();

            self::assertTrue(BookingReference::isValid($reference), $reference);
            // Aucun caractère que l'on confond en le dictant.
            self::assertDoesNotMatchRegularExpression('/[01OIL]/', $reference);
            $references[] = $reference;
        }

        self::assertCount(200, array_unique($references));
    }

    public function testAReferenceIsNormalisedWhateverItIsTyped(): void
    {
        self::assertSame('ABCD-2345', BookingReference::normalise('abcd2345'));
        self::assertSame('ABCD-2345', BookingReference::normalise('ABCD-2345'));
        self::assertSame('ABCD-2345', BookingReference::normalise(' abcd 2345 '));
        self::assertFalse(BookingReference::isValid('ABC'));
        self::assertFalse(BookingReference::isValid('ABCD-0000'));
    }

    public function testABookingIsFoundByItsReference(): void
    {
        $booking = $this->hold();

        $found = $this->repository->findByReference(strtolower(str_replace('-', '', $booking->reference)));

        self::assertNotNull($found);
        self::assertSame($booking->id, $found->id);
    }
}
