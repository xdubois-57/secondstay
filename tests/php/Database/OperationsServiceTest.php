<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Booking\SubStatus;
use SecondStay\Calendar\CalendarScope;
use SecondStay\Calendar\CalendarService;
use SecondStay\Calendar\CalendarTokenRepository;
use SecondStay\Contract\ContractRepository;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Imap\InboundMailRepository;
use SecondStay\Operations\ChecklistItem;
use SecondStay\Operations\ChecklistService;
use SecondStay\Operations\TaskPhase;
use SecondStay\Operations\TaskRepository;
use SecondStay\Backup\BackupService;
use SecondStay\Logging\LogLevel;
use SecondStay\Logging\LogRepository;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Operations\TodoService;
use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\TaskOutcome;
use SecondStay\Scheduler\TaskStateRepository;
use SecondStay\Payment\HoldStatus;
use SecondStay\Payment\PaymentKind;
use SecondStay\Payment\PaymentRepository;
use SecondStay\Payment\PaymentStatus;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;
use SecondStay\Tests\Support\IcsReader;

/**
 * Responsable local, checklists, tableau « À faire » et calendriers privés.
 *
 * Deux invariants portent tout le reste : une checklist **lit** l'état du
 * séjour au lieu de le recopier, et un jeton de calendrier révoqué cesse
 * immédiatement de fonctionner.
 */
final class OperationsServiceTest extends DatabaseTestCase
{
    private ChecklistService $checklists;

    private TodoService $todo;

    private CalendarService $calendar;

    private CalendarTokenRepository $tokens;

    private PaymentRepository $payments;

    private ContractRepository $contracts;

    private TaskRepository $tasks;

    private BookingRepository $bookings;

    private SettingsService $settings;

    private UserRepository $users;

    private User $client;

    private User $manager;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $this->settings->setMany([
            'property.name' => 'Maison des Pins',
            'property.address_line1' => '12 route des Mélèzes',
            'property.city' => 'Chamonix',
            'site.default_locale' => 'fr',
            'site.public_url' => 'https://exemple.test',
            'booking.checkin_time' => '16:00',
            'booking.checkout_time' => '10:00',
            'operations.calendar_enabled' => '1',
        ]);

        $this->bookings = new BookingRepository($this->database);
        $this->users = new UserRepository($this->database);
        $this->payments = new PaymentRepository($this->database);
        $this->contracts = new ContractRepository($this->database);
        $this->tasks = new TaskRepository($this->database);
        $this->tokens = new CalendarTokenRepository($this->database);

        $this->calendar = new CalendarService(
            $this->tokens,
            $this->bookings,
            $this->users,
            $this->settings,
            new Translator(self::projectRoot() . '/translations', 'fr'),
            new Formatter(),
        );

        $this->checklists = new ChecklistService(
            $this->payments,
            $this->contracts,
            $this->tasks,
            $this->calendar,
        );

        $this->todo = new TodoService(
            $this->bookings,
            $this->payments,
            new InboundMailRepository($this->database),
            $this->checklists,
        );

        $this->client = $this->createUser('claire@example.test', Role::Customer);
        $this->manager = $this->createUser('marc@example.test', Role::LocalManager);
        $this->owner = $this->createUser('olivier@example.test', Role::Administrator);
    }

    // --- Responsable local -------------------------------------------------------

    public function testTheAssignedManagerWinsOverTheDefaultOne(): void
    {
        $this->settings->setMany(['operations.default_manager' => (string) $this->owner->id]);
        $booking = $this->booking();

        self::assertSame($this->owner->id, $this->calendar->managerOf($booking)?->id);

        $this->bookings->update($booking->id, ['manager_id' => $this->manager->id]);
        $assigned = $this->bookings->find($booking->id);
        self::assertNotNull($assigned);

        self::assertSame($this->manager->id, $this->calendar->managerOf($assigned)?->id);
    }

    public function testWithoutAnyManagerNoneIsInvented(): void
    {
        self::assertNull($this->calendar->managerOf($this->booking()));
    }

    public function testADeletedManagerFallsBackToTheDefaultOne(): void
    {
        $this->settings->setMany(['operations.default_manager' => (string) $this->owner->id]);

        $booking = $this->booking();
        $this->bookings->update($booking->id, ['manager_id' => $this->manager->id]);

        $this->database->execute('DELETE FROM `user` WHERE `id` = :id', ['id' => $this->manager->id]);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);
        // La suppression du compte n'emporte pas le séjour, seulement son
        // affectation.
        self::assertNull($reloaded->managerId);
        self::assertSame($this->owner->id, $this->calendar->managerOf($reloaded)?->id);
    }

    public function testOnlyOperationalAccountsCanBeManagers(): void
    {
        $candidates = array_map(
            static fn (User $user): string => $user->email,
            $this->users->operational()
        );

        self::assertContains('marc@example.test', $candidates);
        self::assertContains('olivier@example.test', $candidates);
        self::assertNotContains('claire@example.test', $candidates);
    }

    public function testAManagerOnlySeesTheStaysAssignedToThem(): void
    {
        $mine = $this->booking();
        $this->bookings->update($mine->id, ['manager_id' => $this->manager->id]);
        $this->booking('2027-01-04', '2027-01-11');

        $stays = $this->bookings->forManager($this->manager->id, '2020-01-01');

        self::assertCount(1, $stays);
        self::assertSame($mine->reference, $stays[0]->reference);
    }

    // --- Checklists ---------------------------------------------------------------

    public function testDerivedItemsReadTheStayRatherThanCopyIt(): void
    {
        $booking = $this->booking();
        $this->schedulePayments($booking);

        $before = $this->itemsByCode($this->checklists->before($booking));

        self::assertSame(SubStatus::Pending, $before['contract']->status);
        self::assertSame(SubStatus::Pending, $before['deposit']->status);
        self::assertFalse($before['deposit']->manual);

        // L'acompte est encaissé : la checklist doit le refléter sans qu'on
        // la mette à jour.
        $deposit = $this->payments->findKind($booking->id, PaymentKind::Deposit);
        self::assertNotNull($deposit);
        $this->payments->update($deposit->id, ['status' => PaymentStatus::Paid->value]);

        $after = $this->itemsByCode($this->checklists->before($booking));
        self::assertSame(SubStatus::Done, $after['deposit']->status);
    }

    public function testAnAcceptedContractTicksItsLine(): void
    {
        $booking = $this->booking();

        self::assertSame(SubStatus::Pending, $this->itemsByCode($this->checklists->before($booking))['contract']->status);

        $this->contracts->record([
            'booking_id' => $booking->id,
            'version' => '1',
            'locale' => 'fr',
            'sha256' => str_repeat('a', 64),
            'accepted_by' => $this->client->email,
        ]);

        self::assertSame(SubStatus::Done, $this->itemsByCode($this->checklists->before($booking))['contract']->status);
    }

    public function testAComponentThatDoesNotApplyIsNotLate(): void
    {
        $booking = $this->booking();

        // Aucun échéancier : ni acompte, ni solde, ni caution attendus.
        $items = $this->itemsByCode($this->checklists->before($booking));

        self::assertSame(SubStatus::NotApplicable, $items['deposit']->status);
        self::assertFalse($items['deposit']->needsAction());
        self::assertSame(SubStatus::NotApplicable, $items['security_deposit']->status);
    }

    public function testTheSecurityDepositLineFollowsItsHoldCycle(): void
    {
        $booking = $this->booking();
        $this->schedulePayments($booking);

        $hold = $this->payments->findKind($booking->id, PaymentKind::SecurityDeposit);
        self::assertNotNull($hold);

        self::assertSame(SubStatus::Pending, $this->itemsByCode($this->checklists->before($booking))['security_deposit']->status);

        $this->payments->update($hold->id, ['hold_status' => HoldStatus::Received->value]);
        self::assertSame(SubStatus::Done, $this->itemsByCode($this->checklists->before($booking))['security_deposit']->status);

        $this->payments->update($hold->id, ['hold_status' => HoldStatus::ToReturn->value]);
        self::assertSame(SubStatus::Partial, $this->itemsByCode($this->checklists->before($booking))['security_deposit']->status);
    }

    public function testAManualItemIsTickedAndUntickedByAHuman(): void
    {
        $booking = $this->booking();

        $result = $this->checklists->toggle($booking, 'access_shared', true, $this->manager->id, 'code remis');
        self::assertTrue($result['ok'], $result['error']);

        $item = $this->itemsByCode($this->checklists->before($booking))['access_shared'];
        self::assertTrue($item->manual);
        self::assertTrue($item->isDone());
        self::assertSame('code remis', $item->note);
        self::assertNotNull($item->doneAt);

        $this->checklists->toggle($booking, 'access_shared', false, $this->manager->id);
        self::assertFalse($this->itemsByCode($this->checklists->before($booking))['access_shared']->isDone());
    }

    public function testTogglingTheSameItemTwiceDoesNotDuplicateIt(): void
    {
        $booking = $this->booking();

        $this->checklists->toggle($booking, 'cleaning_scheduled', true, $this->manager->id);
        $this->checklists->toggle($booking, 'cleaning_scheduled', false, $this->manager->id);
        $this->checklists->toggle($booking, 'cleaning_scheduled', true, $this->manager->id);

        self::assertCount(1, $this->tasks->forBooking($booking->id));
    }

    public function testAnUnknownChecklistCodeIsRefused(): void
    {
        $booking = $this->booking();

        $result = $this->checklists->toggle($booking, 'code_invente', true, $this->manager->id);

        self::assertFalse($result['ok']);
        self::assertSame('operations.error.unknown_item', $result['error']);
        self::assertSame([], $this->tasks->forBooking($booking->id));
    }

    public function testTheDepartureChecklistIsSeparate(): void
    {
        $booking = $this->booking();
        $departure = $this->itemsByCode($this->checklists->departure($booking));

        self::assertArrayHasKey('inventory_done', $departure);
        self::assertArrayHasKey('cleaning_done', $departure);
        self::assertArrayNotHasKey('contract', $departure);

        foreach ($departure as $item) {
            self::assertSame(TaskPhase::Departure, $item->phase);
        }
    }

    public function testProgressIgnoresLinesWithoutObject(): void
    {
        $booking = $this->booking();

        $empty = $this->checklists->progress($booking);
        self::assertSame(0, $empty['done']);

        foreach (array_merge(ChecklistService::BEFORE_MANUAL, ChecklistService::DEPARTURE_MANUAL) as $code) {
            $this->checklists->toggle($booking, $code, true, $this->manager->id);
        }

        $done = $this->checklists->progress($booking);
        self::assertSame(count(ChecklistService::BEFORE_MANUAL) + count(ChecklistService::DEPARTURE_MANUAL), $done['done']);
        self::assertLessThan($done['total'], $done['done'], 'Contrat et responsable restent à faire.');
    }

    // --- Tableau « À faire » ---------------------------------------------------------

    public function testTheBoardOnlyListsWhatNeedsADecision(): void
    {
        self::assertSame([], $this->todo->items());

        $this->booking();

        $codes = array_column($this->todo->items(), 'code');
        self::assertContains('bookings_to_confirm', $codes);
    }

    public function testAnOverdueInstalmentAppearsOnTheBoard(): void
    {
        $booking = $this->booking();
        $this->payments->create([
            'booking_id' => $booking->id,
            'kind' => PaymentKind::Balance->value,
            'status' => PaymentStatus::Pending->value,
            'amount_cents' => 50000,
            'due_on' => '2020-01-01',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $codes = array_column($this->todo->items(), 'code');

        self::assertContains('payments_overdue', $codes);
    }

    public function testAStayArrivingSoonWithWorkLeftIsListed(): void
    {
        $soon = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('+3 days');
        $booking = $this->booking($soon->format('Y-m-d'), $soon->modify('+5 days')->format('Y-m-d'));
        $this->bookings->update($booking->id, ['status' => BookingStatus::Confirmed->value]);

        $stays = $this->todo->unpreparedStays();

        self::assertCount(1, $stays);
        self::assertSame($booking->reference, $stays[0]['booking']->reference);
        self::assertNotSame([], $stays[0]['outstanding']);
    }

    public function testACancelledStayIsNeverListedAsToPrepare(): void
    {
        $soon = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('+3 days');
        $booking = $this->booking($soon->format('Y-m-d'), $soon->modify('+5 days')->format('Y-m-d'));
        $this->bookings->update($booking->id, ['status' => BookingStatus::Cancelled->value]);

        self::assertSame([], $this->todo->unpreparedStays());
    }

    // --- Tableau « À faire » : contrat, sauvegarde, erreurs, mise à jour ---------------

    /**
     * SPECIFICATIONS.md §50 énumère huit sujets, dont le contrat. Un séjour
     * confirmé sans contrat signé engage les deux parties sans que rien ne
     * dise sur quoi : c'est une décision à prendre, pas une formalité.
     */
    public function testAConfirmedStayWithoutASignedContractIsListed(): void
    {
        $booking = $this->booking('2099-06-01', '2099-06-08');
        $this->bookings->update($booking->id, ['status' => BookingStatus::Confirmed->value]);

        self::assertContains('contracts_pending', array_column($this->fullTodo()->items(), 'code'));
    }

    public function testASignedContractLeavesTheBoard(): void
    {
        $booking = $this->booking('2099-06-01', '2099-06-08');
        $this->bookings->update($booking->id, [
            'status' => BookingStatus::Confirmed->value,
            'contract_status' => SubStatus::Done->value,
        ]);

        self::assertNotContains('contracts_pending', array_column($this->fullTodo()->items(), 'code'));
    }

    /**
     * Un séjour terminé n'a plus de contrat à signer : le tableau doit
     * montrer ce qui reste à décider, pas l'archive de ce qui n'a pas été
     * fait il y a trois ans.
     */
    public function testAPastStayIsNotListedAsAPendingContract(): void
    {
        $booking = $this->booking('2020-06-01', '2020-06-08');
        $this->bookings->update($booking->id, ['status' => BookingStatus::Confirmed->value]);

        self::assertNotContains('contracts_pending', array_column($this->fullTodo()->items(), 'code'));
    }

    public function testTheAbsenceOfAnyBackupIsListedAsUrgent(): void
    {
        $items = $this->fullTodo()->items();

        $backup = null;
        foreach ($items as $item) {
            if ($item['code'] === 'backup_missing') {
                $backup = $item;
            }
        }

        self::assertNotNull($backup);
        self::assertSame('danger', $backup['severity']);
    }

    /**
     * Une sauvegarde récente retire l'alerte ; une sauvegarde qui date la
     * remet, avec une gravité moindre — la perte de données est bornée.
     */
    public function testABackupAgesOutOfFreshness(): void
    {
        $created = $this->backups()->create(false);

        self::assertNotContains('backup_missing', array_column($this->fullTodo()->items(), 'code'));
        self::assertNotContains('backup_stale', array_column($this->fullTodo()->items(), 'code'));

        touch($created['path'], time() - 30 * 86400);

        $items = $this->fullTodo()->items();
        $codes = array_column($items, 'code');
        self::assertContains('backup_stale', $codes);
        self::assertNotContains('backup_missing', $codes);

        // Le compte est un nombre de choses à traiter, pas un âge : « 30 » se
        // lirait comme trente sauvegardes en retard.
        foreach ($items as $item) {
            if ($item['code'] === 'backup_stale') {
                self::assertSame(1, $item['count']);
            }
        }
    }

    public function testRecentErrorsAreListedAndOlderOnesAreNot(): void
    {
        $this->logEntry(LogLevel::Error, gmdate('Y-m-d H:i:s', time() - 3600));
        $this->logEntry(LogLevel::Critical, gmdate('Y-m-d H:i:s', time() - 7200));
        // Hors fenêtre : une panne d'il y a trois jours n'est plus une
        // décision à prendre aujourd'hui.
        $this->logEntry(LogLevel::Error, gmdate('Y-m-d H:i:s', time() - 3 * 86400));
        // Un avertissement n'est pas une erreur.
        $this->logEntry(LogLevel::Warning, gmdate('Y-m-d H:i:s', time() - 3600));

        $errors = null;
        foreach ($this->fullTodo()->items() as $item) {
            if ($item['code'] === 'errors_recent') {
                $errors = $item;
            }
        }

        self::assertNotNull($errors);
        self::assertSame(2, $errors['count']);
    }

    /**
     * La disponibilité d'une mise à jour est lue dans le résultat de la tâche
     * périodique : construire ce tableau ne doit jamais dépendre d'un appel
     * sortant.
     */
    public function testAnAvailableUpdateIsReadFromTheScheduledTaskNotFromTheNetwork(): void
    {
        $tasks = new TaskStateRepository($this->database);
        $now = gmdate('Y-m-d H:i:s');

        $tasks->claim(ScheduledTask::UpdateCheck, $now, gmdate('Y-m-d H:i:s', time() - 1));
        $tasks->release(ScheduledTask::UpdateCheck, $now, TaskOutcome::ok('scheduler.detail.up_to_date'), 1);
        self::assertNotContains('update_available', array_column($this->fullTodo()->items(), 'code'));

        $tasks->claim(ScheduledTask::UpdateCheck, $now, gmdate('Y-m-d H:i:s', time() - 1));
        $tasks->release(ScheduledTask::UpdateCheck, $now, TaskOutcome::ok('scheduler.detail.update_available'), 1);
        self::assertContains('update_available', array_column($this->fullTodo()->items(), 'code'));
    }

    /**
     * La pastille du courrier non rattaché compte la boîte entière.
     *
     * Une pastille plafonnée à la taille d'une page annoncerait « 50 » à une
     * boîte qui en compte deux cents : le propriétaire croirait avoir fini
     * bien avant la fin, et cesserait de regarder.
     */
    public function testTheUnlinkedMailBadgeCountsTheWholeMailboxNotAPage(): void
    {
        for ($index = 0; $index < 60; $index++) {
            $this->database->insert('mail_message', [
                'direction' => 'inbound',
                'booking_id' => null,
                'user_id' => null,
                'message_id' => 'msg-' . $index . '@example.test',
                'from_address' => 'inconnu' . $index . '@example.test',
                'to_address' => 'contact@example.test',
                'subject' => 'Sujet ' . $index,
                'body_text' => 'Corps du message.',
                'status' => 'received',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $items = array_column($this->todo->items(), 'count', 'code');

        self::assertSame(60, $items['mail_unlinked'] ?? 0);
    }

    private function fullTodo(): TodoService
    {
        return new TodoService(
            $this->bookings,
            $this->payments,
            new InboundMailRepository($this->database),
            $this->checklists,
            null,
            null,
            null,
            null,
            $this->backups(),
            new LogRepository($this->database),
            new TaskStateRepository($this->database),
        );
    }

    private function backups(): BackupService
    {
        return new BackupService(
            $this->database,
            $this->paths,
            new MaintenanceMode($this->storagePath . '/maintenance.json'),
            '0.15.0',
        );
    }

    private function logEntry(LogLevel $level, string $at): void
    {
        $this->database->insert('app_log', [
            'created_at' => $at,
            'level' => $level->value,
            'category' => 'test',
            'message' => 'entrée de test',
            'context' => null,
            'user_id' => null,
            'correlation_id' => 'test',
        ]);
    }

    // --- Calendriers privés -------------------------------------------------------------

    public function testTheAdminFeedListsEveryOccupyingStay(): void
    {
        $first = $this->booking();
        $second = $this->booking('2027-02-01', '2027-02-08');
        $cancelled = $this->booking('2027-03-01', '2027-03-08');
        $this->bookings->update($cancelled->id, ['status' => BookingStatus::Cancelled->value]);

        $token = $this->calendar->tokenFor(CalendarScope::Admin, $this->owner);
        $feed = $this->calendar->feedFor($token);

        self::assertNotNull($feed);
        $events = (new IcsReader($feed))->events();

        $summaries = implode("\n", array_column($events, 'SUMMARY'));
        self::assertStringContainsString($first->reference, $summaries);
        self::assertStringContainsString($second->reference, $summaries);
        self::assertStringNotContainsString($cancelled->reference, $summaries);
    }

    public function testTheManagerFeedNeverCarriesAmounts(): void
    {
        $booking = $this->booking();

        $adminFeed = (string) $this->calendar->feedFor($this->calendar->tokenFor(CalendarScope::Admin, $this->owner));
        $managerFeed = (string) $this->calendar->feedFor($this->calendar->tokenFor(CalendarScope::Manager, $this->manager));

        $adminEvent = (new IcsReader($adminFeed))->events()[0];
        $managerEvent = (new IcsReader($managerFeed))->events()[0];

        self::assertStringContainsString('780,00', $adminEvent['DESCRIPTION']);
        self::assertStringNotContainsString('780,00', $managerEvent['DESCRIPTION']);
        // Le responsable a besoin de savoir qui arrive, pas de combien.
        self::assertStringContainsString($booking->guestName, $managerEvent['DESCRIPTION']);
    }

    public function testTheCustomerFeedShowsOnlyTheirStayAndTheManagerContact(): void
    {
        $mine = $this->booking();
        $this->bookings->update($mine->id, ['manager_id' => $this->manager->id]);
        $other = $this->booking('2027-02-01', '2027-02-08');

        $reloaded = $this->bookings->find($mine->id);
        self::assertNotNull($reloaded);

        $token = $this->calendar->tokenFor(CalendarScope::Customer, $this->client, $reloaded);
        $feed = (string) $this->calendar->feedFor($token);
        $events = (new IcsReader($feed))->events();

        self::assertCount(1, $events);
        self::assertStringNotContainsString($other->reference, $feed);

        // Le contact du responsable figure au flux du voyageur
        // (SPECIFICATIONS.md §51).
        self::assertStringContainsString('marc@example.test', $events[0]['DESCRIPTION']);
        // Mais pas les montants.
        self::assertStringNotContainsString('780,00', $events[0]['DESCRIPTION']);
    }

    public function testARevokedTokenStopsWorkingImmediately(): void
    {
        $this->booking();
        $token = $this->calendar->tokenFor(CalendarScope::Admin, $this->owner);

        self::assertNotNull($this->calendar->feedFor($token));

        $entry = $this->tokens->activeFor(CalendarScope::Admin, $this->owner->id);
        self::assertNotNull($entry);
        $this->tokens->revoke($entry->id);

        self::assertNull($this->calendar->feedFor($token), 'Révoquer doit couper l’accès sans délai.');
    }

    public function testRegeneratingRevokesThePreviousLink(): void
    {
        $this->booking();

        $first = $this->calendar->tokenFor(CalendarScope::Admin, $this->owner);
        $second = $this->calendar->tokenFor(CalendarScope::Admin, $this->owner);

        self::assertNotSame($first, $second);
        self::assertNull($this->calendar->feedFor($first));
        self::assertNotNull($this->calendar->feedFor($second));
    }

    public function testTheTokenIsNeverStoredInClear(): void
    {
        $token = $this->calendar->tokenFor(CalendarScope::Admin, $this->owner);

        $rows = $this->database->fetchAll('SELECT * FROM `calendar_token`');
        self::assertNotSame([], $rows);

        foreach ($rows as $row) {
            self::assertNotSame($token, (string) $row['token_hash']);
            self::assertSame(hash('sha256', $token), (string) $row['token_hash']);
        }
    }

    public function testAnUnknownTokenYieldsNothing(): void
    {
        self::assertNull($this->calendar->feedFor(str_repeat('0', 64)));
        self::assertNull($this->calendar->feedFor(''));
    }

    public function testUsingAFeedRecordsItsLastUse(): void
    {
        $this->booking();
        $token = $this->calendar->tokenFor(CalendarScope::Admin, $this->owner);

        $before = $this->tokens->activeFor(CalendarScope::Admin, $this->owner->id);
        self::assertNotNull($before);
        self::assertFalse($before->wasUsed());

        $this->calendar->feedFor($token);

        $after = $this->tokens->find($before->id);
        self::assertNotNull($after);
        self::assertTrue($after->wasUsed());
    }

    public function testTheFeedFollowsTheLanguageOfTheStay(): void
    {
        $booking = $this->booking();
        $this->database->update('booking', ['locale' => 'de'], ['id' => $booking->id]);

        $reloaded = $this->bookings->find($booking->id);
        self::assertNotNull($reloaded);

        $token = $this->calendar->tokenFor(CalendarScope::Customer, $this->client, $reloaded);
        $feed = (string) $this->calendar->feedFor($token);

        self::assertStringContainsString('Mein Aufenthalt', $feed);
    }

    // --- Outils ------------------------------------------------------------------------

    /**
     * @param list<ChecklistItem> $items
     *
     * @return array<string, ChecklistItem>
     */
    private function itemsByCode(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item->code] = $item;
        }

        return $indexed;
    }

    private function schedulePayments(Booking $booking): void
    {
        foreach ([
            [PaymentKind::Deposit, 23400],
            [PaymentKind::Balance, 54600],
            [PaymentKind::SecurityDeposit, 50000],
        ] as [$kind, $amount]) {
            $this->payments->create([
                'booking_id' => $booking->id,
                'kind' => $kind->value,
                'status' => PaymentStatus::Pending->value,
                'amount_cents' => $amount,
                'due_on' => '2026-06-04',
                'hold_status' => $kind === PaymentKind::SecurityDeposit
                    ? HoldStatus::ToPay->value
                    : HoldStatus::None->value,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    private function booking(string $arrival = '2026-12-04', string $departure = '2026-12-11'): Booking
    {
        $id = $this->database->insert('booking', [
            'reference' => strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 4))
                . '-' . strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 4)),
            'user_id' => $this->client->id,
            'status' => BookingStatus::ToConfirm->value,
            'arrival' => $arrival,
            'departure' => $departure,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => 'claire@example.test',
            'guest_name' => 'Claire Dubois',
            'guest_phone' => '+33600000000',
            'accommodation_cents' => 70000,
            'cleaning_cents' => 8000,
            'total_cents' => 78000,
            'deposit_cents' => 23400,
            'security_deposit_cents' => 50000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $booking = $this->bookings->find($id);
        self::assertNotNull($booking);

        return $booking;
    }

    private function createUser(string $email, Role $role): User
    {
        $id = $this->users->create(
            $email,
            self::passwordHash('Marée-Haute-2026!'),
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
