<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Availability\AvailabilityBlockRepository;
use SecondStay\Availability\AvailabilityService;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingService;
use SecondStay\Booking\BookingStatus;
use SecondStay\Booking\PromoCodeRepository;
use SecondStay\Booking\StayRules;
use SecondStay\Booking\WaitlistRepository;
use SecondStay\Logging\Logger;
use SecondStay\Payment\FakePaymentProvider;
use SecondStay\Payment\HoldStatus;
use SecondStay\Payment\Payment;
use SecondStay\Payment\PaymentKind;
use SecondStay\Payment\PaymentRepository;
use SecondStay\Payment\PaymentService;
use SecondStay\Payment\PaymentStatus;
use SecondStay\Payment\WebhookRepository;
use SecondStay\Pricing\PriceCalculator;
use SecondStay\Pricing\RateRepository;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tax\TouristTaxCalculator;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Échéancier, encaissements, webhooks et caution.
 *
 * Deux invariants sont attaqués directement : un webhook rejoué ne doit rien
 * produire de plus, et seul un paiement constaté **chez le fournisseur**
 * confirme un séjour.
 */
final class PaymentServiceTest extends DatabaseTestCase
{
    private PaymentService $payments;

    private PaymentRepository $repository;

    private WebhookRepository $webhooks;

    private BookingRepository $bookings;

    private BookingService $bookingService;

    private FakePaymentProvider $provider;

    private SettingsService $settings;

    private UserRepository $users;

    private User $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PaymentRepository($this->database);
        $this->webhooks = new WebhookRepository($this->database);
        $this->bookings = new BookingRepository($this->database);
        $this->provider = new FakePaymentProvider();
        $this->users = new UserRepository($this->database);

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $this->settings->setMany([
            'pricing.default_night_price' => '100,00',
            'pricing.cleaning_price' => '80,00',
            'pricing.cleaning_mode' => 'mandatory',
            'pricing.deposit_percent' => '30',
            'pricing.security_deposit' => '500,00',
            'booking.min_nights' => '2',
            'booking.max_guests' => '6',
            'booking.hold_minutes' => '30',
            'booking.requires_approval' => '1',
            'payment.balance_days_before' => '30',
            'tax.tourist_enabled' => '1',
            'tax.tourist_per_adult_night' => '1,50',
            'tax.tourist_cap_per_stay' => '0',
        ]);

        $rates = new RateRepository($this->database);
        $prices = new PriceCalculator($this->settings, $rates);
        $rules = new StayRules($this->settings, 'Europe/Paris');
        $events = new BookingEventRepository($this->database);
        $logger = new Logger($this->storagePath . '/logs');

        $this->bookingService = new BookingService(
            $this->bookings,
            $events,
            new PromoCodeRepository($this->database),
            new WaitlistRepository($this->database),
            $rules,
            new AvailabilityService(
                new AvailabilityBlockRepository($this->database),
                $rates,
                $prices,
                $rules,
                'Europe/Paris',
                $this->bookings,
            ),
            $prices,
            $this->settings,
            $logger,
        );

        $this->payments = new PaymentService(
            $this->repository,
            $this->webhooks,
            $this->bookings,
            $events,
            $this->bookingService,
            $this->provider,
            $this->settings,
            new TouristTaxCalculator($this->settings),
            $logger,
            $this->users,
            null,
            new AuditTrail($this->database),
        );

        $this->client = $this->createUser('claire@example.test', Role::Customer);
        $this->owner = $this->createUser('olivier@example.test', Role::Administrator);
    }

    // --- Échéancier -----------------------------------------------------------

    public function testScheduleCreatesOneComponentPerFinancialItem(): void
    {
        $booking = $this->booking();

        $kinds = array_map(
            static fn (Payment $payment): string => $payment->kind->value,
            $this->payments->schedule($booking)
        );

        self::assertContains(PaymentKind::Deposit->value, $kinds);
        self::assertContains(PaymentKind::Balance->value, $kinds);
        self::assertContains(PaymentKind::SecurityDeposit->value, $kinds);
        self::assertContains(PaymentKind::TouristTax->value, $kinds);
    }

    public function testScheduleIsIdempotent(): void
    {
        $booking = $this->booking();

        $first = $this->payments->schedule($booking);
        $second = $this->payments->schedule($booking);

        self::assertSame(count($first), count($second));
        self::assertSame(
            array_map(static fn (Payment $p): int => $p->id, $first),
            array_map(static fn (Payment $p): int => $p->id, $second),
        );
    }

    public function testScheduledAmountsMatchTheBooking(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);

        $deposit = $this->repository->findKind($booking->id, PaymentKind::Deposit);
        $balance = $this->repository->findKind($booking->id, PaymentKind::Balance);

        self::assertNotNull($deposit);
        self::assertNotNull($balance);
        self::assertSame($booking->depositCents, $deposit->amountCents);
        self::assertSame($booking->balanceCents(), $balance->amountCents);
        self::assertSame($booking->totalCents, $deposit->amountCents + $balance->amountCents);
    }

    public function testTouristTaxCountsOnlyAdults(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);

        $tax = $this->repository->findKind($booking->id, PaymentKind::TouristTax);

        self::assertNotNull($tax);
        // 150 centimes × 2 adultes × 7 nuits, les mineurs étant exonérés.
        self::assertSame(150 * 2 * 7, $tax->amountCents);
    }

    public function testBalanceIsDueTodayWhenTheStayIsCloserThanTheConfiguredDelay(): void
    {
        $booking = $this->booking(10);

        self::assertSame(gmdate('Y-m-d'), $this->payments->balanceDueDate($booking));
    }

    public function testBalanceDueDateFollowsTheConfiguredDelay(): void
    {
        $booking = $this->booking(90);
        $expected = $booking->range->arrival->modify('-30 days')->format('Y-m-d');

        self::assertSame($expected, $this->payments->balanceDueDate($booking));
    }

    // --- Encaissement par le fournisseur -------------------------------------

    public function testDepositPaidAtTheProviderConfirmsTheBooking(): void
    {
        $booking = $this->booking();
        $deposit = $this->start($booking, PaymentKind::Deposit);

        $this->provider->settle($deposit->providerReference);
        $result = $this->payments->handleWebhook(['id' => $deposit->providerReference], '{}');

        self::assertTrue($result['ok']);
        self::assertSame('applied', $result['status']);

        $updated = $this->repository->find($deposit->id);
        self::assertNotNull($updated);
        self::assertSame(PaymentStatus::Paid, $updated->status);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);
        self::assertSame(BookingStatus::Confirmed, $reloaded->status);
    }

    public function testAReplayedWebhookChangesNothing(): void
    {
        $booking = $this->booking();
        $deposit = $this->start($booking, PaymentKind::Deposit);
        $this->provider->settle($deposit->providerReference);

        $first = $this->payments->handleWebhook(['id' => $deposit->providerReference], '{}');
        $second = $this->payments->handleWebhook(['id' => $deposit->providerReference], '{}');
        $third = $this->payments->handleWebhook(['id' => $deposit->providerReference], '{}');

        self::assertSame('applied', $first['status']);
        self::assertSame('duplicate', $second['status']);
        self::assertSame('duplicate', $third['status']);
        self::assertCount(1, $this->webhooks->recent(10));
    }

    public function testAWebhookForAnUnknownPaymentIsAcknowledgedAndIgnored(): void
    {
        $booking = $this->booking();
        $deposit = $this->start($booking, PaymentKind::Deposit);

        // Une référence connue du fournisseur mais absente de l'application.
        $orphan = $this->provider->create(1000, 'EUR', 'orphelin', '', '');
        self::assertTrue($orphan['ok']);
        self::assertNotSame($deposit->providerReference, $orphan['reference']);

        $result = $this->payments->handleWebhook(['id' => $orphan['reference']], '{}');

        self::assertTrue($result['ok'], 'Le fournisseur ne doit pas réessayer indéfiniment.');
        self::assertSame('unknown', $result['status']);
    }

    public function testAnUnreadableWebhookIsRejected(): void
    {
        $result = $this->payments->handleWebhook(['id' => 'tr_inconnu'], '{}');

        self::assertFalse($result['ok']);
        self::assertSame('invalid', $result['status']);
        self::assertSame('payment.error.invalid_webhook', $result['error']);
    }

    public function testTheWebhookBodyIsNeverTrusted(): void
    {
        $booking = $this->booking();
        $deposit = $this->start($booking, PaymentKind::Deposit);

        // Le corps prétend que tout est payé ; le fournisseur, lui, ne l'a pas
        // constaté. L'application doit suivre le fournisseur.
        $result = $this->payments->handleWebhook(
            ['id' => $deposit->providerReference, 'status' => 'paid', 'amount' => '9999.00'],
            '{"status":"paid"}'
        );

        self::assertTrue($result['ok']);
        self::assertSame('ignored', $result['status']);

        $updated = $this->repository->find($deposit->id);
        self::assertNotNull($updated);
        self::assertSame(PaymentStatus::Pending, $updated->status);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);
        self::assertNotSame(BookingStatus::Confirmed, $reloaded->status);
    }

    public function testAnUnexpectedAmountIsRefused(): void
    {
        $booking = $this->booking();
        $deposit = $this->start($booking, PaymentKind::Deposit);

        self::assertFalse($this->payments->applyStatus($deposit, PaymentStatus::Paid, $deposit->amountCents - 1));

        $updated = $this->repository->find($deposit->id);
        self::assertNotNull($updated);
        self::assertSame(PaymentStatus::Pending, $updated->status);
    }

    public function testAFinalStatusIsNeverOverwrittenByALateNotification(): void
    {
        $booking = $this->booking();
        $deposit = $this->start($booking, PaymentKind::Deposit);

        self::assertTrue($this->payments->applyStatus($deposit, PaymentStatus::Paid, $deposit->amountCents));

        $paid = $this->repository->find($deposit->id);
        self::assertNotNull($paid);

        // Notification arrivée dans le désordre : elle ne doit rien défaire.
        self::assertFalse($this->payments->applyStatus($paid, PaymentStatus::Failed));

        $final = $this->repository->find($deposit->id);
        self::assertNotNull($final);
        self::assertSame(PaymentStatus::Paid, $final->status);
    }

    public function testAFailedPaymentLeavesTheBookingUnconfirmed(): void
    {
        $booking = $this->booking();
        $deposit = $this->start($booking, PaymentKind::Deposit);

        $this->provider->settle($deposit->providerReference, PaymentStatus::Failed);
        $this->payments->handleWebhook(['id' => $deposit->providerReference], '{}');

        $updated = $this->repository->find($deposit->id);
        self::assertNotNull($updated);
        self::assertSame(PaymentStatus::Failed, $updated->status);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);
        self::assertNotSame(BookingStatus::Confirmed, $reloaded->status);
    }

    // --- Encaissement manuel ---------------------------------------------------

    public function testAManualPaymentDoesNotConfirmTheBookingOnItsOwn(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);
        $deposit = $this->repository->findKind($booking->id, PaymentKind::Deposit);
        self::assertNotNull($deposit);

        $result = $this->payments->recordManualPayment($deposit, $this->owner);

        self::assertTrue($result['ok']);

        $updated = $this->repository->find($deposit->id);
        self::assertNotNull($updated);
        self::assertSame(PaymentStatus::Paid, $updated->status);
        self::assertSame(PaymentService::METHOD_TRANSFER, $updated->method);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);
        self::assertNotSame(
            BookingStatus::Confirmed,
            $reloaded->status,
            'Un virement ne confirme jamais seul (SPECIFICATIONS.md §30).'
        );
    }

    public function testAManualPaymentConfirmsTheBookingOnlyWhenExplicitlyAsked(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);
        $deposit = $this->repository->findKind($booking->id, PaymentKind::Deposit);
        self::assertNotNull($deposit);

        $this->payments->recordManualPayment($deposit, $this->owner, true);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);
        self::assertSame(BookingStatus::Confirmed, $reloaded->status);
    }

    public function testAnAlreadySettledPaymentCannotBeCollectedTwice(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);
        $deposit = $this->repository->findKind($booking->id, PaymentKind::Deposit);
        self::assertNotNull($deposit);

        $this->payments->recordManualPayment($deposit, $this->owner);
        $reloaded = $this->repository->find($deposit->id);
        self::assertNotNull($reloaded);

        $result = $this->payments->recordManualPayment($reloaded, $this->owner);

        self::assertFalse($result['ok']);
        self::assertSame('payment.error.already_settled', $result['error']);
    }

    // --- Remboursements ---------------------------------------------------------

    public function testAPartialRefundLeavesTheRemainderCollected(): void
    {
        $deposit = $this->paidDeposit();

        $result = $this->payments->refund($deposit, 1000, $this->owner, 'geste commercial');
        self::assertTrue($result['ok'], $result['error']);

        $updated = $this->repository->find($deposit->id);
        self::assertNotNull($updated);
        self::assertSame(PaymentStatus::PartiallyRefunded, $updated->status);
        self::assertSame(1000, $updated->refundedCents);
        self::assertSame($deposit->amountCents - 1000, $updated->netCents());
    }

    public function testAFullRefundMarksThePaymentRefunded(): void
    {
        $deposit = $this->paidDeposit();

        self::assertTrue($this->payments->refund($deposit, $deposit->amountCents, $this->owner)['ok']);

        $updated = $this->repository->find($deposit->id);
        self::assertNotNull($updated);
        self::assertSame(PaymentStatus::Refunded, $updated->status);
        self::assertSame(0, $updated->netCents());
    }

    public function testRefundingMoreThanCollectedIsRefused(): void
    {
        $deposit = $this->paidDeposit();

        $result = $this->payments->refund($deposit, $deposit->amountCents + 1, $this->owner);

        self::assertFalse($result['ok']);
        self::assertSame('payment.error.refund_amount', $result['error']);
    }

    public function testAnUncollectedPaymentCannotBeRefunded(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);
        $deposit = $this->repository->findKind($booking->id, PaymentKind::Deposit);
        self::assertNotNull($deposit);

        $result = $this->payments->refund($deposit, 100, $this->owner);

        self::assertFalse($result['ok']);
        self::assertSame('payment.error.not_settled', $result['error']);
    }

    // --- Caution ------------------------------------------------------------------

    public function testTheSecurityDepositFollowsItsOwnCycle(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);

        $hold = $this->repository->findKind($booking->id, PaymentKind::SecurityDeposit);
        self::assertNotNull($hold);
        self::assertSame(HoldStatus::ToPay, $hold->holdStatus);

        $this->payments->recordManualPayment($hold, $this->owner);
        $received = $this->repository->find($hold->id);
        self::assertNotNull($received);
        self::assertSame(HoldStatus::Received, $received->holdStatus);

        self::assertTrue($this->payments->markDepositToReturn($received, $this->owner)['ok']);
        $toReturn = $this->repository->find($hold->id);
        self::assertNotNull($toReturn);
        self::assertSame(HoldStatus::ToReturn, $toReturn->holdStatus);

        self::assertTrue($this->payments->refund($toReturn, $toReturn->amountCents, $this->owner)['ok']);
        $returned = $this->repository->find($hold->id);
        self::assertNotNull($returned);
        self::assertSame(HoldStatus::Returned, $returned->holdStatus);
    }

    public function testAPartiallyRetainedDepositIsRecordedAsSuch(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);
        $hold = $this->repository->findKind($booking->id, PaymentKind::SecurityDeposit);
        self::assertNotNull($hold);

        $this->payments->recordManualPayment($hold, $this->owner);
        $received = $this->repository->find($hold->id);
        self::assertNotNull($received);

        $this->payments->refund($received, $received->amountCents - 5000, $this->owner, 'dégâts');

        $updated = $this->repository->find($hold->id);
        self::assertNotNull($updated);
        self::assertSame(HoldStatus::PartiallyRetained, $updated->holdStatus);
    }

    public function testAnUnreceivedDepositCannotBeMarkedToReturn(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);
        $hold = $this->repository->findKind($booking->id, PaymentKind::SecurityDeposit);
        self::assertNotNull($hold);

        $result = $this->payments->markDepositToReturn($hold, $this->owner);

        self::assertFalse($result['ok']);
        self::assertSame('payment.error.hold_transition', $result['error']);
    }

    // --- Suivi ----------------------------------------------------------------------

    public function testOutstandingListsUnsettledComponentsWithTheirBooking(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);

        $rows = $this->repository->outstanding();

        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertSame($booking->reference, $row['reference']);
            self::assertFalse($row['payment']->status->isSettled());
        }
    }

    public function testHeldDepositsListsOnlyReceivedSecurityDeposits(): void
    {
        $booking = $this->booking();
        $this->payments->schedule($booking);

        self::assertSame([], $this->repository->heldDeposits());

        $hold = $this->repository->findKind($booking->id, PaymentKind::SecurityDeposit);
        self::assertNotNull($hold);
        $this->payments->recordManualPayment($hold, $this->owner);

        $rows = $this->repository->heldDeposits();
        self::assertCount(1, $rows);
        self::assertSame(PaymentKind::SecurityDeposit, $rows[0]['payment']->kind);
    }

    public function testEveryChangeLeavesATrace(): void
    {
        $deposit = $this->paidDeposit();
        $this->payments->refund($deposit, 500, $this->owner, 'geste');

        $types = array_column($this->repository->history($deposit->id), 'type');

        self::assertContains('scheduled', $types);
        self::assertContains('started', $types);
        self::assertContains('status_paid', $types);
        self::assertContains('refunded', $types);
    }

    // --- Outils ----------------------------------------------------------------------

    private function booking(int $offsetDays = 90, int $nights = 7): Booking
    {
        $arrival = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))
            ->modify('+' . $offsetDays . ' days');

        $result = $this->bookingService->hold([
            'arrival' => $arrival->format('Y-m-d'),
            'departure' => $arrival->modify('+' . $nights . ' days')->format('Y-m-d'),
            'adults' => 2,
            'children' => 1,
        ], $this->client);

        self::assertTrue($result['ok'], (string) json_encode($result));

        $submitted = $this->bookingService->submit(
            $result['booking'],
            ['phone' => '+33600000000', 'accept_rules' => '1'],
            $this->client,
        );
        self::assertTrue($submitted['ok'], (string) json_encode($submitted));

        return $submitted['booking'];
    }

    private function start(Booking $booking, PaymentKind $kind): Payment
    {
        $this->payments->schedule($booking);

        $payment = $this->repository->findKind($booking->id, $kind);
        self::assertNotNull($payment);

        $result = $this->payments->start($payment, 'https://example.test/return', 'https://example.test/webhook');
        self::assertTrue($result['ok'], $result['error']);

        $started = $this->repository->find($payment->id);
        self::assertNotNull($started);
        self::assertNotSame('', $started->providerReference);

        return $started;
    }

    private function paidDeposit(): Payment
    {
        $booking = $this->booking();
        $deposit = $this->start($booking, PaymentKind::Deposit);

        $this->provider->settle($deposit->providerReference);
        $this->payments->handleWebhook(['id' => $deposit->providerReference], '{}');

        $paid = $this->repository->find($deposit->id);
        self::assertNotNull($paid);
        self::assertSame(PaymentStatus::Paid, $paid->status);

        return $paid;
    }

    private function createUser(string $email, Role $role): User
    {
        $id = $this->users->create(
            $email,
            (new PasswordHasher())->hash('Marée-Haute-2026!'),
            'Prénom',
            'Nom',
            '+33600000000',
            $role,
            'fr',
            UserStatus::Active,
        );

        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user;
    }
}
