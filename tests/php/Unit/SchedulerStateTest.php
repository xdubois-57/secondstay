<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\TaskOutcome;
use SecondStay\Scheduler\TaskState;

/**
 * Horloge du planificateur (ARCHITECTURE.md §23).
 *
 * Le planificateur ne dispose d'aucun démon pour compter le temps : tout se
 * déduit d'un horodatage en base et de l'heure courante. Ces règles-là sont
 * donc le cœur du mécanisme, et se vérifient sans base.
 */
final class SchedulerStateTest extends TestCase
{
    public function testATaskNeverRunIsDueImmediately(): void
    {
        $state = TaskState::never(ScheduledTask::Backup);

        self::assertTrue($state->isDue('2026-05-01 03:00:00'));
    }

    /**
     * Une tâche jamais exécutée n'est pas « en retard » : juste après une
     * installation, tout serait rouge alors que rien n'est cassé.
     */
    public function testATaskNeverRunIsNotStale(): void
    {
        self::assertFalse(TaskState::never(ScheduledTask::InboundMail)->isStale('2026-05-01 03:00:00'));
    }

    public function testATaskIsNotDueBeforeItsInterval(): void
    {
        $state = $this->state(ScheduledTask::CalendarImport, '2026-05-01 03:00:00');

        // Intervalle d'une heure : cinquante-neuf minutes ne suffisent pas.
        self::assertFalse($state->isDue('2026-05-01 03:59:00'));
        self::assertTrue($state->isDue('2026-05-01 04:00:00'));
    }

    /**
     * Trois intervalles manqués font une panne — mais jamais avant trois
     * heures.
     *
     * Le plancher n'est pas une commodité : une bonne partie des hébergements
     * mutualisés n'offre qu'un cron **horaire**, et la relève de courrier veut
     * passer toutes les quinze minutes. Sans plancher, ces installations
     * afficheraient une alerte permanente alors qu'elles fonctionnent — et un
     * écran de diagnostics rouge en permanence est un écran qu'on cesse de
     * lire, ce qui coûte plus cher que l'absence de diagnostic.
     */
    public function testStalenessNeverFiresBeforeAnHourlyCronCouldHavePassed(): void
    {
        $state = $this->state(ScheduledTask::InboundMail, '2026-05-01 03:00:00');

        // Un cron horaire vient de passer : rien à signaler.
        self::assertFalse($state->isStale('2026-05-01 04:00:00'));
        // Deux heures et demie de silence restent tolérables.
        self::assertFalse($state->isStale('2026-05-01 05:30:00'));
        // Trois heures : même un cron horaire a cessé de passer.
        self::assertTrue($state->isStale('2026-05-01 06:00:00'));
    }

    /**
     * Pour les tâches lentes, ce sont bien trois intervalles qui comptent :
     * le plancher ne les rend pas plus permissives.
     */
    public function testASlowTaskIsStaleAfterThreeOfItsOwnIntervals(): void
    {
        $state = $this->state(ScheduledTask::Backup, '2026-05-01 03:00:00');

        self::assertSame(1440 * 3, ScheduledTask::Backup->staleAfterMinutes());
        self::assertFalse($state->isStale('2026-05-03 03:00:00'));
        self::assertTrue($state->isStale('2026-05-04 03:00:00'));
    }

    public function testALockInThePastIsNoLongerALock(): void
    {
        $state = new TaskState(ScheduledTask::Backup, null, 'never', '', 0, 0, '2026-05-01 03:00:00', 0, 0);

        self::assertTrue($state->isLocked('2026-05-01 02:59:59'));
        self::assertFalse($state->isLocked('2026-05-01 03:00:00'));
    }

    /**
     * Un horodatage illisible ne doit pas geler une tâche pour toujours :
     * mieux vaut une exécution de trop qu'une sauvegarde qui ne repart jamais.
     */
    public function testAnUnreadableTimestampMakesTheTaskDue(): void
    {
        $state = $this->state(ScheduledTask::Backup, 'pas-une-date');

        self::assertTrue($state->isDue('2026-05-01 03:00:00'));
    }

    public function testEveryTaskHasAPositiveIntervalAndALongerStaleWindow(): void
    {
        foreach (ScheduledTask::all() as $task) {
            self::assertGreaterThan(0, $task->intervalMinutes(), $task->value);
            self::assertGreaterThan($task->intervalMinutes(), $task->staleAfterMinutes(), $task->value);
            self::assertGreaterThanOrEqual(
                ScheduledTask::MINIMUM_STALE_MINUTES,
                $task->staleAfterMinutes(),
                $task->value . ' : un cron horaire ne doit jamais déclencher d’alerte.'
            );
            self::assertGreaterThan(0, $task->lockMinutes(), $task->value);
            self::assertSame('scheduler.task.' . $task->value, $task->labelKey());
        }
    }

    public function testUnknownCodeIsRejected(): void
    {
        self::assertNull(ScheduledTask::tryFromCode('drop_database'));
        self::assertSame(ScheduledTask::Backup, ScheduledTask::tryFromCode('backup'));
    }

    /**
     * « Ignorée » ne doit jamais se confondre avec « réussie » : sinon une
     * boîte non configurée se lit comme une boîte relevée.
     */
    public function testSkippedIsNotAnError(): void
    {
        $skipped = TaskOutcome::skipped('scheduler.detail.disabled');

        self::assertFalse($skipped->isError());
        self::assertSame(TaskOutcome::SKIPPED, $skipped->status);
        self::assertSame(0, $skipped->count);
    }

    public function testANegativeCountIsClamped(): void
    {
        self::assertSame(0, TaskOutcome::ok('scheduler.detail.purged', -3)->count);
    }

    private function state(ScheduledTask $task, string $lastRunAt): TaskState
    {
        return new TaskState($task, $lastRunAt, 'ok', '', 0, 0, null, 0, 1);
    }
}
