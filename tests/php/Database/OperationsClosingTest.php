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
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Calendar\ExternalCalendar;
use SecondStay\Calendar\ExternalCalendarRepository;
use SecondStay\Calendar\ExternalCalendarService;
use SecondStay\Calendar\IcsParser;
use SecondStay\Contract\ContractRepository;
use SecondStay\Dispute\DisputeRepository;
use SecondStay\Dispute\DisputeService;
use SecondStay\Dispute\DisputeStatus;
use SecondStay\Http\FakeHttpFetcher;
use SecondStay\Http\UrlGuard;
use SecondStay\I18n\Translator;
use SecondStay\Incident\IncidentRepository;
use SecondStay\Inspection\InspectionRepository;
use SecondStay\Inspection\ZoneRepository;
use SecondStay\Logging\Logger;
use SecondStay\Payment\HoldStatus;
use SecondStay\Payment\PaymentKind;
use SecondStay\Payment\PaymentRepository;
use SecondStay\Payment\PaymentStatus;
use SecondStay\Pricing\DateRange;
use SecondStay\Reporting\ReportPeriod;
use SecondStay\Reporting\ReportService;
use SecondStay\Tests\Support\DatabaseTestCase;
use SecondStay\Tests\Support\XlsxReader;

/**
 * Clôture de l'exploitation : import ICS, reporting, litiges
 * (ROADMAP.md itération 14 ; SPECIFICATIONS.md §52, §66 et §68).
 *
 * Trois promesses y sont vérifiées bout en bout :
 *
 * 1. un flux externe bloque des nuits **sans jamais** toucher ce que le
 *    propriétaire a décidé lui-même, et un flux muet ne libère rien ;
 * 2. le reporting compte ce qui a été encaissé, attendu et détenu — la
 *    caution n'est pas un revenu, la taxe non plus ;
 * 3. un litige ne peut ni réclamer plus que la caution détenue, ni se clore
 *    sans dire comment.
 */
final class OperationsClosingTest extends DatabaseTestCase
{
    private const FEED = 'https://calendar.example.test/airbnb.ics';

    private const OTHER_FEED = 'https://calendar.example.test/booking.ics';

    private ExternalCalendarRepository $calendars;

    private ExternalCalendarService $sync;

    private AvailabilityBlockRepository $blocks;

    private FakeHttpFetcher $http;

    private BookingRepository $bookings;

    private PaymentRepository $payments;

    private DisputeRepository $disputes;

    private DisputeService $disputeService;

    private ReportService $reports;

    private UserRepository $users;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $logger = new Logger($this->storagePath . '/logs');

        // Les hôtes de test ne se résolvent pas : la résolution est injectée,
        // mais le contrôle des plages privées reste celui du produit.
        $this->http = new FakeHttpFetcher(new UrlGuard([], static function (string $host): array {
            return str_ends_with($host, '.example.test') ? ['93.184.216.34'] : [];
        }));

        $this->calendars = new ExternalCalendarRepository($this->database);
        $this->blocks = new AvailabilityBlockRepository($this->database);
        $this->sync = new ExternalCalendarService(
            $this->calendars,
            $this->blocks,
            new IcsParser(),
            $this->http,
            $logger,
            new AuditTrail($this->database),
        );

        $this->bookings = new BookingRepository($this->database);
        $this->payments = new PaymentRepository($this->database);
        $this->users = new UserRepository($this->database);
        $this->disputes = new DisputeRepository($this->database);
        $this->disputeService = new DisputeService(
            $this->disputes,
            $this->payments,
            new InspectionRepository($this->database, new ZoneRepository($this->database)),
            new IncidentRepository($this->database),
            new ContractRepository($this->database),
            $logger,
        );

        $this->reports = new ReportService(
            $this->database,
            $this->payments,
            new Translator(self::projectRoot() . '/translations', 'fr'),
            'EUR',
        );

        $this->client = $this->createUser('claire@example.test');
    }

    // --- Import ICS -----------------------------------------------------------------

    private function ics(string ...$events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
    }

    private function event(string $uid, string $start, string $end, string $summary = 'Reserved'): string
    {
        return "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTART;VALUE=DATE:{$start}\r\n"
            . "DTEND;VALUE=DATE:{$end}\r\nSUMMARY:{$summary}\r\nEND:VEVENT\r\n";
    }

    private function calendar(string $url = self::FEED, string $provider = 'airbnb'): ExternalCalendar
    {
        $id = $this->calendars->create($url, 'Flux de test', $provider);
        $calendar = $this->calendars->find($id);
        self::assertNotNull($calendar);

        return $calendar;
    }

    public function testASyncBlocksTheNightsPublishedByTheFeed(): void
    {
        $this->http->addResponse(self::FEED, $this->ics(
            $this->event('a-1', '20260701', '20260706'),
            $this->event('a-2', '20260810', '20260812'),
        ));

        $result = $this->sync->sync($this->calendar());

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(2, $result['events']);

        $blocked = $this->blocks->blockedNights('2026-07-01', '2026-07-10');

        // `DTEND` est exclusif : la nuit du 5 est occupée, celle du 6 non.
        self::assertArrayHasKey('2026-07-05', $blocked);
        self::assertArrayNotHasKey('2026-07-06', $blocked);
        self::assertSame(AvailabilityBlockRepository::KIND_EXTERNAL, $blocked['2026-07-01']['kind']);
    }

    public function testASyncRecordsItsStatusAndCount(): void
    {
        $this->http->addResponse(self::FEED, $this->ics($this->event('a-1', '20260701', '20260706')));
        $calendar = $this->calendar();

        $this->sync->sync($calendar);
        $stored = $this->calendars->find($calendar->id);

        self::assertNotNull($stored);
        self::assertSame('ok', $stored->lastStatus);
        self::assertSame(1, $stored->lastEvents);
        self::assertNotNull($stored->lastSyncAt);
        self::assertFalse($stored->hasFailed());
    }

    public function testASecondSyncReplacesOnlyItsOwnBlocks(): void
    {
        $calendar = $this->calendar();

        // Un blocage décidé par le propriétaire, sans provenance externe.
        $manual = $this->blocks->create(
            DateRange::fromStrings('2026-07-20', '2026-07-25'),
            AvailabilityBlockRepository::KIND_OWNER,
            'Séjour famille',
            $this->client->id,
        );

        $this->http->addResponse(self::FEED, $this->ics($this->event('a-1', '20260701', '20260706')));
        $this->sync->sync($calendar);

        $this->http->addResponse(self::FEED, $this->ics($this->event('a-2', '20260901', '20260903')));
        $this->sync->sync($calendar);

        $fromFeed = $this->blocks->forSource($calendar->id);

        self::assertCount(1, $fromFeed);
        self::assertSame('2026-09-01', (string) $fromFeed[0]['start_day']);
        // Ce que le propriétaire a bloqué à la main survit à l'import.
        self::assertNotNull($this->blocks->find($manual));
        self::assertSame([], $this->blocks->blockedNights('2026-07-01', '2026-07-05'));
    }

    public function testTwoFeedsDoNotEraseEachOther(): void
    {
        $first = $this->calendar();
        $second = $this->calendar(self::OTHER_FEED, 'booking');

        $this->http->addResponse(self::FEED, $this->ics($this->event('a-1', '20260701', '20260706')));
        $this->http->addResponse(self::OTHER_FEED, $this->ics($this->event('b-1', '20260801', '20260806')));

        $this->sync->sync($first);
        $this->sync->sync($second);
        $this->sync->sync($first);

        self::assertCount(1, $this->blocks->forSource($first->id));
        self::assertCount(1, $this->blocks->forSource($second->id));
    }

    public function testAFeedThatDoesNotAnswerFreesNothing(): void
    {
        $calendar = $this->calendar();
        $this->http->addResponse(self::FEED, $this->ics($this->event('a-1', '20260701', '20260706')));
        $this->sync->sync($calendar);

        $this->http->addResponse(self::FEED, '', 503);
        $result = $this->sync->sync($calendar);

        self::assertFalse($result['ok']);
        self::assertSame('calendar.import.error.unavailable', $result['error']);
        // Rendre disponibles des nuits vendues ailleurs serait le pire
        // résultat possible : les blocages restent.
        self::assertCount(1, $this->blocks->forSource($calendar->id));

        $stored = $this->calendars->find($calendar->id);
        self::assertNotNull($stored);
        self::assertSame('http_503', $stored->lastStatus);
        self::assertTrue($stored->hasFailed());
        self::assertSame('calendar.import.status.unavailable', $stored->statusLabelKey());
    }

    public function testAnHtmlPageIsNotTreatedAsAnEmptyCalendar(): void
    {
        $calendar = $this->calendar();
        $this->http->addResponse(self::FEED, $this->ics($this->event('a-1', '20260701', '20260706')));
        $this->sync->sync($calendar);

        $this->http->addResponse(self::FEED, '<html><body>Connexion requise</body></html>');
        $result = $this->sync->sync($calendar);

        self::assertFalse($result['ok']);
        self::assertSame('calendar.import.error.not_a_calendar', $result['error']);
        self::assertCount(1, $this->blocks->forSource($calendar->id));
    }

    public function testAnEmptyButValidCalendarDoesFreeTheNights(): void
    {
        $calendar = $this->calendar();
        $this->http->addResponse(self::FEED, $this->ics($this->event('a-1', '20260701', '20260706')));
        $this->sync->sync($calendar);

        // Un calendrier réellement vide est une information : la plateforme
        // dit que plus rien n'est vendu.
        $this->http->addResponse(self::FEED, $this->ics());
        $result = $this->sync->sync($calendar);

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame([], $this->blocks->forSource($calendar->id));
    }

    public function testAMutedCalendarIsNotSynchronised(): void
    {
        $calendar = $this->calendar();
        $this->calendars->setActive($calendar->id, false);
        $muted = $this->calendars->find($calendar->id);
        self::assertNotNull($muted);

        $result = $this->sync->sync($muted);

        self::assertFalse($result['ok']);
        self::assertSame('calendar.import.error.inactive', $result['error']);
        self::assertSame([], $this->http->requestedUrls);
    }

    public function testSyncAllOnlyVisitsActiveFeeds(): void
    {
        $active = $this->calendar();
        $muted = $this->calendar(self::OTHER_FEED, 'booking');
        $this->calendars->setActive($muted->id, false);

        $this->http->addResponse(self::FEED, $this->ics($this->event('a-1', '20260701', '20260706')));

        $summary = $this->sync->syncAll();

        self::assertSame(1, $summary['calendars']);
        self::assertSame(1, $summary['events']);
        self::assertSame(0, $summary['failed']);
        self::assertSame([self::FEED], $this->http->requestedUrls);
        self::assertNotSame(0, $active->id);
    }

    public function testDeletingAFeedRemovesTheBlocksItCreated(): void
    {
        $calendar = $this->calendar();
        $this->http->addResponse(self::FEED, $this->ics($this->event('a-1', '20260701', '20260706')));
        $this->sync->sync($calendar);

        $this->calendars->delete($calendar->id);

        // Sans leur source, ces blocages deviendraient des indisponibilités
        // sans provenance : ils partent avec elle.
        self::assertSame([], $this->blocks->forSource($calendar->id));
        self::assertSame([], $this->blocks->blockedNights('2026-07-01', '2026-07-05'));
    }

    public function testAFeedPointingAtThePrivateNetworkIsRefused(): void
    {
        $calendar = $this->calendar('http://127.0.0.1/admin/calendar.ics', 'other');

        $result = $this->sync->sync($calendar);

        self::assertFalse($result['ok']);
        self::assertSame('calendar.import.error.blocked', $result['error']);
    }

    // --- Reporting -------------------------------------------------------------------

    public function testTheReportSeparatesRevenueTaxAndDeposit(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->payment($booking, PaymentKind::Balance, 90_000, PaymentStatus::Paid);
        $this->payment($booking, PaymentKind::TouristTax, 5_600, PaymentStatus::Paid);
        $this->payment(
            $booking,
            PaymentKind::SecurityDeposit,
            50_000,
            PaymentStatus::Paid,
            HoldStatus::Received
        );

        $report = $this->reports->build(ReportPeriod::month(2026, 6));

        self::assertSame(90_000, $report->receivedCents);
        self::assertSame(5_600, $report->touristTaxCents);
        self::assertSame(50_000, $report->depositsHeldCents);
        self::assertSame(7, $report->nightsSold);
        self::assertSame(1, $report->staysCount);
    }

    public function testWhatIsNotPaidIsCountedAsOutstanding(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->payment($booking, PaymentKind::Deposit, 30_000, PaymentStatus::Paid);
        $this->payment($booking, PaymentKind::Balance, 60_000, PaymentStatus::Pending);

        $report = $this->reports->build(ReportPeriod::month(2026, 6));

        self::assertSame(30_000, $report->receivedCents);
        self::assertSame(90_000, $report->expectedCents);
        self::assertSame(60_000, $report->outstandingCents());
    }

    public function testAStayStraddlingTwoMonthsIsCountedInBoth(): void
    {
        $booking = $this->booking('2026-06-28', '2026-07-04');
        $this->payment($booking, PaymentKind::Balance, 60_000, PaymentStatus::Paid);

        $june = $this->reports->build(ReportPeriod::month(2026, 6));
        $july = $this->reports->build(ReportPeriod::month(2026, 7));

        // 28, 29, 30 juin ; 1, 2, 3 juillet — la nuit du départ ne compte pas.
        self::assertSame(3, $june->nightsSold);
        self::assertSame(3, $july->nightsSold);
        self::assertSame(6, $booking->nights());
    }

    public function testACancelledStayIsNotCounted(): void
    {
        $this->booking('2026-06-05', '2026-06-12', BookingStatus::Cancelled);

        self::assertTrue($this->reports->build(ReportPeriod::month(2026, 6))->isEmpty());
    }

    public function testARefundLowersWhatWasReceived(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $payment = $this->payment($booking, PaymentKind::Balance, 90_000, PaymentStatus::Paid);
        $this->payments->update($payment, [
            'refunded_cents' => 20_000,
            'refunded_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $report = $this->reports->build(ReportPeriod::month(2026, 6));

        self::assertSame(70_000, $report->receivedCents);
        self::assertSame(20_000, $report->refundedCents);
    }

    public function testAReturnedDepositIsNoLongerHeld(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->payment(
            $booking,
            PaymentKind::SecurityDeposit,
            50_000,
            PaymentStatus::Paid,
            HoldStatus::Returned
        );

        self::assertSame(0, $this->reports->build(ReportPeriod::month(2026, 6))->depositsHeldCents);
    }

    public function testTheYearAggregatesEveryMonth(): void
    {
        $first = $this->booking('2026-06-05', '2026-06-12');
        $second = $this->booking('2026-09-05', '2026-09-08');
        $this->payment($first, PaymentKind::Balance, 90_000, PaymentStatus::Paid);
        $this->payment($second, PaymentKind::Balance, 40_000, PaymentStatus::Paid);

        $report = $this->reports->build(ReportPeriod::year(2026));

        self::assertSame(130_000, $report->receivedCents);
        self::assertSame(10, $report->nightsSold);
        self::assertSame([2026], $this->reports->years());
    }

    public function testTheWorkbookIsReadableAndCarriesTheFigures(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->payment($booking, PaymentKind::Balance, 90_000, PaymentStatus::Paid);
        $this->payment($booking, PaymentKind::TouristTax, 5_600, PaymentStatus::Paid);

        $report = $this->reports->build(ReportPeriod::month(2026, 6));
        $reader = new XlsxReader($this->reports->workbook($report, 'fr'));

        self::assertSame(['Synthèse', 'Séjours'], $reader->sheetNames());
        self::assertSame(['Encaissé', '900'], $reader->rowStartingWith('Synthèse', 'Encaissé'));
        self::assertSame(['Période', '2026-06'], $reader->rowStartingWith('Synthèse', 'Période'));
        self::assertSame('secondstay-2026-06.xlsx', $this->reports->filename($report));

        $stay = $reader->rowStartingWith('Séjours', $booking->reference);
        self::assertSame('2026-06-05', $stay[1]);
        self::assertSame('7', $stay[4]);
    }

    public function testTheWorkbookFollowsTheLocale(): void
    {
        $this->booking('2026-06-05', '2026-06-12');
        $report = $this->reports->build(ReportPeriod::month(2026, 6));

        $german = new XlsxReader($this->reports->workbook($report, 'de'));

        self::assertSame(['Übersicht', 'Aufenthalte'], $german->sheetNames());
        self::assertNotSame([], $german->rowStartingWith('Übersicht', 'Eingenommen'));
    }

    public function testAnEmptyPeriodStillProducesAReadableWorkbook(): void
    {
        $report = $this->reports->build(ReportPeriod::month(2026, 6));
        $reader = new XlsxReader($this->reports->workbook($report, 'fr'));

        self::assertSame(['Encaissé', '0'], $reader->rowStartingWith('Synthèse', 'Encaissé'));
        self::assertCount(1, $reader->rows('Séjours'));
    }

    // --- Litiges ----------------------------------------------------------------------

    /**
     * @return array{ok: bool, dispute: \SecondStay\Dispute\Dispute|null, error: string}
     */
    private function openDispute(Booking $booking, int $claimed = 20_000, string $kind = 'deposit'): array
    {
        return $this->disputeService->open($booking, $kind, $claimed, 'Carrelage fêlé', 'fr', $this->client);
    }

    public function testADisputeGathersWhatWasAlreadyCollected(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->payment(
            $booking,
            PaymentKind::SecurityDeposit,
            50_000,
            PaymentStatus::Paid,
            HoldStatus::Received
        );

        $evidence = $this->disputeService->evidenceFor($booking);

        self::assertSame(50_000, $evidence['deposit_held_cents']);
        self::assertFalse($evidence['checkout_completed']);
        self::assertSame(0, $evidence['incidents']);
        self::assertFalse($evidence['contract_accepted']);
    }

    public function testAClaimAboveTheHeldDepositIsRefused(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->payment(
            $booking,
            PaymentKind::SecurityDeposit,
            50_000,
            PaymentStatus::Paid,
            HoldStatus::Received
        );

        $result = $this->openDispute($booking, 60_000);

        self::assertFalse($result['ok']);
        self::assertSame('dispute.error.above_deposit', $result['error']);
        self::assertSame([], $this->disputes->forBooking($booking->id));
    }

    public function testADisputeWithoutASubjectIsRefused(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');

        $result = $this->disputeService->open($booking, 'damage', 0, '   ', 'fr', $this->client);

        self::assertFalse($result['ok']);
        self::assertSame('dispute.error.summary_required', $result['error']);
    }

    public function testOpeningADisputeRecordsItsFirstEvent(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->payment(
            $booking,
            PaymentKind::SecurityDeposit,
            50_000,
            PaymentStatus::Paid,
            HoldStatus::Received
        );

        $result = $this->openDispute($booking);
        $dispute = $result['dispute'];

        self::assertTrue($result['ok'], $result['error']);
        self::assertNotNull($dispute);
        self::assertSame(DisputeStatus::Open, $dispute->status);
        self::assertSame($booking->reference, $dispute->bookingReference);
        self::assertCount(1, $dispute->events);
        self::assertSame('opened', $dispute->events[0]->type);
        self::assertSame(1, $this->disputes->countOpen());
    }

    public function testASecondDisputeOfTheSameKindIsRefused(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->disputeService->open($booking, 'damage', 0, 'Store cassé', 'fr', $this->client);

        $again = $this->disputeService->open($booking, 'damage', 0, 'Store cassé encore', 'fr', $this->client);

        self::assertFalse($again['ok']);
        self::assertSame('dispute.error.already_open', $again['error']);
        self::assertCount(1, $this->disputes->forBooking($booking->id));
        // Le premier litige n'a pas été réécrit par la seconde tentative.
        self::assertSame('Store cassé', $this->disputes->forBooking($booking->id)[0]->summary);
    }

    public function testADisputeOfAnotherKindIsAllowedOnTheSameStay(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->disputeService->open($booking, 'damage', 0, 'Store cassé', 'fr', $this->client);
        $second = $this->disputeService->open($booking, 'payment', 0, 'Virement manquant', 'fr', $this->client);

        self::assertTrue($second['ok'], $second['error']);
        self::assertCount(2, $this->disputes->forBooking($booking->id));
    }

    public function testAnUnknownKindFallsBackToOther(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $result = $this->disputeService->open($booking, 'inventé', 0, 'Objet', 'fr', $this->client);

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame('other', $result['dispute']?->kind);
    }

    public function testClosingADisputeRequiresAnExplanation(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $dispute = $this->disputeService->open($booking, 'damage', 10_000, 'Store cassé', 'fr', $this->client)['dispute'];
        self::assertNotNull($dispute);

        $result = $this->disputeService->transition($dispute, DisputeStatus::Resolved, 5_000, '  ', $this->client);

        self::assertFalse($result['ok']);
        self::assertSame('dispute.error.resolution_required', $result['error']);
        self::assertSame(DisputeStatus::Open, $this->disputes->find($dispute->id)?->status);
    }

    public function testSettlingMoreThanClaimedIsRefused(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $dispute = $this->disputeService->open($booking, 'damage', 10_000, 'Store cassé', 'fr', $this->client)['dispute'];
        self::assertNotNull($dispute);

        $result = $this->disputeService->transition(
            $dispute,
            DisputeStatus::Resolved,
            15_000,
            'Accord amiable',
            $this->client
        );

        self::assertFalse($result['ok']);
        self::assertSame('dispute.error.settlement', $result['error']);
    }

    public function testAResolvedDisputeKeepsItsSettlementAndDate(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $dispute = $this->disputeService->open($booking, 'damage', 10_000, 'Store cassé', 'fr', $this->client)['dispute'];
        self::assertNotNull($dispute);

        $result = $this->disputeService->transition(
            $dispute,
            DisputeStatus::Resolved,
            4_000,
            'Moitié prise en charge par l’assurance',
            $this->client
        );

        self::assertTrue($result['ok'], $result['error']);

        $stored = $this->disputes->find($dispute->id);
        self::assertNotNull($stored);
        self::assertTrue($stored->status->isResolved());
        self::assertSame(4_000, $stored->settledCents);
        self::assertSame(6_000, $stored->waivedCents());
        self::assertNotNull($stored->resolvedAt);
        self::assertSame(0, $this->disputes->countOpen());
    }

    public function testReopeningADisputeClearsItsResolution(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $dispute = $this->disputeService->open($booking, 'damage', 10_000, 'Store cassé', 'fr', $this->client)['dispute'];
        self::assertNotNull($dispute);

        $this->disputeService->transition($dispute, DisputeStatus::Resolved, 4_000, 'Accord', $this->client);
        $resolved = $this->disputes->find($dispute->id);
        self::assertNotNull($resolved);

        $this->disputeService->transition($resolved, DisputeStatus::Discussing, 0, '', $this->client);

        $reopened = $this->disputes->find($dispute->id);
        self::assertNotNull($reopened);
        self::assertSame(DisputeStatus::Discussing, $reopened->status);
        // Garder la date de résolution ferait croire à un litige clos.
        self::assertNull($reopened->resolvedAt);
        self::assertSame(1, $this->disputes->countOpen());
    }

    public function testAnUnauthorisedTransitionIsRefused(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $dispute = $this->disputeService->open($booking, 'damage', 0, 'Store cassé', 'fr', $this->client)['dispute'];
        self::assertNotNull($dispute);

        $this->disputeService->transition($dispute, DisputeStatus::Discussing, 0, '', $this->client);
        $discussing = $this->disputes->find($dispute->id);
        self::assertNotNull($discussing);

        $result = $this->disputeService->transition($discussing, DisputeStatus::Open, 0, '', $this->client);

        self::assertFalse($result['ok']);
        self::assertSame('dispute.error.transition', $result['error']);
    }

    public function testAnEmptyCommentIsRefused(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $dispute = $this->disputeService->open($booking, 'damage', 0, 'Store cassé', 'fr', $this->client)['dispute'];
        self::assertNotNull($dispute);

        $result = $this->disputeService->comment($dispute, '   ', $this->client);

        self::assertFalse($result['ok']);
        self::assertSame('dispute.error.note_required', $result['error']);
        $stored = $this->disputes->find($dispute->id);
        self::assertNotNull($stored);
        self::assertCount(1, $stored->events);
    }

    public function testTheHistoryKeepsEveryStepInOrder(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $dispute = $this->disputeService->open($booking, 'damage', 10_000, 'Store cassé', 'fr', $this->client)['dispute'];
        self::assertNotNull($dispute);

        $this->disputeService->comment($dispute, 'Photos envoyées', $this->client);
        $this->disputeService->transition($dispute, DisputeStatus::Discussing, 0, '', $this->client);
        $discussing = $this->disputes->find($dispute->id);
        self::assertNotNull($discussing);
        $this->disputeService->transition($discussing, DisputeStatus::Resolved, 3_000, 'Accord', $this->client);

        $stored = $this->disputes->find($dispute->id);
        self::assertNotNull($stored);
        $events = $stored->events;

        self::assertSame(
            ['opened', 'comment', 'discussing', 'resolved'],
            array_map(static fn (object $event): string => $event->type, $events)
        );
        self::assertSame('Claire Dubois', $events[1]->actorLabel);
    }

    public function testDeletingAStayTakesItsDisputesWithIt(): void
    {
        $booking = $this->booking('2026-06-05', '2026-06-12');
        $this->disputeService->open($booking, 'damage', 0, 'Store cassé', 'fr', $this->client);

        $this->database->delete('booking', ['id' => $booking->id]);

        self::assertSame(0, $this->disputes->countOpen());
    }

    // --- Fixtures ----------------------------------------------------------------------

    private function booking(
        string $arrival,
        string $departure,
        BookingStatus $status = BookingStatus::Confirmed,
    ): Booking {
        $id = $this->database->insert('booking', [
            'reference' => strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 4))
                . '-' . strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 4)),
            'user_id' => $this->client->id,
            'status' => $status->value,
            'arrival' => $arrival,
            'departure' => $departure,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => 'claire@example.test',
            'guest_name' => 'Claire Dubois',
            'guest_phone' => '+33600000000',
            'total_cents' => 90_000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $booking = $this->bookings->find($id);
        self::assertNotNull($booking);

        return $booking;
    }

    private function payment(
        Booking $booking,
        PaymentKind $kind,
        int $amountCents,
        PaymentStatus $status,
        HoldStatus $hold = HoldStatus::None,
    ): int {
        return $this->payments->create([
            'booking_id' => $booking->id,
            'kind' => $kind->value,
            'status' => $status->value,
            'amount_cents' => $amountCents,
            'refunded_cents' => 0,
            'currency' => 'EUR',
            'method' => 'transfer',
            'due_on' => $booking->range->arrival->format('Y-m-d'),
            'provider' => 'manual',
            'description' => $kind->value,
            'hold_status' => $hold->value,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'paid_at' => $status === PaymentStatus::Paid ? gmdate('Y-m-d H:i:s') : null,
        ]);
    }

    private function createUser(string $email): User
    {
        $id = $this->users->create(
            $email,
            (new PasswordHasher())->hash('Marée-Haute-2026!'),
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
}
