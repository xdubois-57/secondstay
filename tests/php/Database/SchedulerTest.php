<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Logging\Logger;
use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\Scheduler;
use SecondStay\Scheduler\TaskOutcome;
use SecondStay\Scheduler\TaskStateRepository;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Planificateur de tâches périodiques (ARCHITECTURE.md §23).
 *
 * Ce que ces tests protègent :
 *
 * 1. **le verrou**, parce que deux passages cron peuvent se chevaucher sur un
 *    hébergement mutualisé et qu'une double relève de courrier importerait
 *    deux fois les mêmes pièces jointes ;
 * 2. **l'isolement des échecs** : une boîte injoignable ne doit pas empêcher
 *    la sauvegarde de la nuit ;
 * 3. **l'honnêteté de l'état** : une tâche ignorée ne doit jamais se lire
 *    comme une tâche réussie.
 */
final class SchedulerTest extends DatabaseTestCase
{
    private TaskStateRepository $states;

    private Scheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->states = new TaskStateRepository($this->database);
        $this->scheduler = new Scheduler(
            $this->states,
            new Logger($this->storagePath . '/logs'),
            new AuditTrail($this->database),
        );
    }

    public function testEveryKnownTaskIsListedEvenBeforeItEverRan(): void
    {
        $states = $this->states->all();

        self::assertCount(count(ScheduledTask::all()), $states);
        foreach ($states as $state) {
            self::assertNull($state->lastRunAt);
            self::assertSame('never', $state->lastStatus);
        }
    }

    public function testRunningATaskRecordsItsOutcome(): void
    {
        $this->scheduler->register(
            ScheduledTask::Retention,
            static fn (): TaskOutcome => TaskOutcome::ok('scheduler.detail.purged', 12)
        );

        $result = $this->scheduler->runNow(ScheduledTask::Retention);

        self::assertSame('ok', $result['status']);
        self::assertSame(12, $result['count']);

        $state = $this->states->state(ScheduledTask::Retention);
        self::assertSame('ok', $state->lastStatus);
        self::assertSame('scheduler.detail.purged', $state->lastDetail);
        self::assertSame(12, $state->lastCount);
        self::assertSame(1, $state->runs);
        self::assertNotNull($state->lastRunAt);
    }

    /**
     * Le verrou n'est pas un confort : sans lui, deux passages cron qui se
     * chevauchent relèvent la même boîte deux fois.
     */
    public function testATaskAlreadyLockedIsNotRunAgain(): void
    {
        $calls = 0;
        $this->scheduler->register(ScheduledTask::InboundMail, static function () use (&$calls): TaskOutcome {
            $calls++;

            return TaskOutcome::ok();
        });

        $future = gmdate('Y-m-d H:i:s', time() + 600);
        self::assertTrue($this->states->claim(ScheduledTask::InboundMail, gmdate('Y-m-d H:i:s'), $future));

        $result = $this->scheduler->runNow(ScheduledTask::InboundMail);

        self::assertSame('skipped', $result['status']);
        self::assertSame('scheduler.detail.locked', $result['detail']);
        self::assertSame(0, $calls);
    }

    public function testAnExpiredLockDoesNotBlockTheTaskForever(): void
    {
        $past = gmdate('Y-m-d H:i:s', time() - 3600);
        $this->states->claim(ScheduledTask::InboundMail, gmdate('Y-m-d H:i:s', time() - 7200), $past);

        $this->scheduler->register(ScheduledTask::InboundMail, static fn (): TaskOutcome => TaskOutcome::ok());

        self::assertSame('ok', $this->scheduler->runNow(ScheduledTask::InboundMail)['status']);
    }

    /**
     * Une tâche qui lève ne doit ni faire tomber le passage cron, ni laisser
     * son verrou derrière elle : la suivante doit tourner, et la tâche en
     * échec doit pouvoir être relancée.
     */
    public function testAThrowingTaskIsRecordedAndReleasesItsLock(): void
    {
        $this->scheduler->register(ScheduledTask::Backup, static function (): TaskOutcome {
            throw new \RuntimeException('disque plein sur /srv/backup');
        });
        $this->scheduler->register(
            ScheduledTask::Retention,
            static fn (): TaskOutcome => TaskOutcome::ok('scheduler.detail.purged', 1)
        );

        $results = $this->scheduler->runDue();

        $byTask = [];
        foreach ($results as $result) {
            $byTask[$result['task']] = $result['status'];
        }

        self::assertSame('error', $byTask['backup'] ?? '');
        self::assertSame('ok', $byTask['retention'] ?? '', 'un échec ne doit pas emporter les autres tâches');

        $state = $this->states->state(ScheduledTask::Backup);
        self::assertSame('error', $state->lastStatus);
        self::assertSame(1, $state->consecutiveFailures);
        self::assertFalse($state->isLocked(gmdate('Y-m-d H:i:s')));

        // Le message d'exception peut porter un chemin : seule une clé de
        // traduction est conservée.
        self::assertSame('scheduler.detail.exception', $state->lastDetail);
    }

    public function testConsecutiveFailuresResetOnSuccess(): void
    {
        $failures = 2;
        $this->scheduler->register(ScheduledTask::UpdateCheck, static function () use (&$failures): TaskOutcome {
            if ($failures > 0) {
                $failures--;

                return TaskOutcome::error('scheduler.detail.exception');
            }

            return TaskOutcome::ok();
        });

        $this->scheduler->runNow(ScheduledTask::UpdateCheck);
        $this->scheduler->runNow(ScheduledTask::UpdateCheck);
        self::assertSame(2, $this->states->state(ScheduledTask::UpdateCheck)->consecutiveFailures);

        $this->scheduler->runNow(ScheduledTask::UpdateCheck);
        self::assertSame(0, $this->states->state(ScheduledTask::UpdateCheck)->consecutiveFailures);
    }

    public function testATaskWithoutHandlerIsNeverRun(): void
    {
        $result = $this->scheduler->runNow(ScheduledTask::LocalContent);

        self::assertSame('skipped', $result['status']);
        self::assertSame('scheduler.detail.no_handler', $result['detail']);
        self::assertSame('never', $this->states->state(ScheduledTask::LocalContent)->lastStatus);
    }

    public function testOnlyDueTasksRun(): void
    {
        $this->scheduler->register(ScheduledTask::Backup, static fn (): TaskOutcome => TaskOutcome::ok());

        self::assertCount(1, $this->scheduler->runDue());
        // L'intervalle de la sauvegarde est quotidien : le passage suivant du
        // cron, quelques secondes plus tard, ne doit rien relancer.
        self::assertSame([], $this->scheduler->runDue());
    }

    public function testLastSuccessfulRunIgnoresTasksThatNeverRan(): void
    {
        self::assertNull($this->states->lastSuccessfulRun());

        $this->scheduler->register(ScheduledTask::Retention, static fn (): TaskOutcome => TaskOutcome::ok());
        $this->scheduler->runNow(ScheduledTask::Retention);

        self::assertNotNull($this->states->lastSuccessfulRun());
    }

    /**
     * Le départ d'un séjour ne se déduit pas de son arrivée : deux séjours de
     * durées différentes peuvent partir le même jour.
     */
    public function testDeparturesAreFoundByTheirOwnDate(): void
    {
        $client = $this->createUser('claire@example.test');
        $bookings = new BookingRepository($this->database);

        $short = $this->createBooking($client, '2026-07-10', '2026-07-12');
        $long = $this->createBooking($client, '2026-06-20', '2026-07-12');
        $this->createBooking($client, '2026-07-05', '2026-07-11');

        $leaving = array_map(
            static fn (object $booking): int => $booking->id,
            $bookings->endingOn('2026-07-12')
        );

        self::assertSame([$short, $long], $leaving);
    }

    public function testCancelledStaysAreNotAnnouncedAsDepartures(): void
    {
        $client = $this->createUser('claire@example.test');
        $this->createBooking($client, '2026-07-10', '2026-07-12', BookingStatus::Cancelled);

        self::assertSame([], (new BookingRepository($this->database))->endingOn('2026-07-12'));
    }

    private function createUser(string $email): User
    {
        $users = new UserRepository($this->database);
        $id = $users->create(
            $email,
            self::passwordHash('Marée-Haute-2026!'),
            'Claire',
            'Dubois',
            '+33600000000',
            Role::Customer,
            'fr',
            UserStatus::Active,
        );

        $user = $users->findById($id);
        self::assertNotNull($user);

        return $user;
    }

    private function createBooking(
        User $user,
        string $arrival,
        string $departure,
        BookingStatus $status = BookingStatus::Confirmed,
    ): int {
        return $this->database->insert('booking', [
            'reference' => strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))),
            'user_id' => $user->id,
            'status' => $status->value,
            'arrival' => $arrival,
            'departure' => $departure,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => $user->email,
            'guest_name' => $user->displayName(),
            'guest_phone' => '+33600000000',
            'total_cents' => 90_000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
