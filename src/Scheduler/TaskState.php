<?php

declare(strict_types=1);

namespace SecondStay\Scheduler;

use DateTimeImmutable;
use DateTimeZone;

/**
 * État persistant d'une tâche périodique.
 */
final class TaskState
{
    public function __construct(
        public readonly ScheduledTask $task,
        public readonly ?string $lastRunAt,
        public readonly string $lastStatus,
        public readonly string $lastDetail,
        public readonly int $lastCount,
        public readonly int $lastDurationMs,
        public readonly ?string $lockedUntil,
        public readonly int $consecutiveFailures,
        public readonly int $runs,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(ScheduledTask $task, array $row): self
    {
        return new self(
            $task,
            is_string($row['last_run_at'] ?? null) ? $row['last_run_at'] : null,
            is_string($row['last_status'] ?? null) ? $row['last_status'] : 'never',
            is_string($row['last_detail'] ?? null) ? $row['last_detail'] : '',
            (int) ($row['last_count'] ?? 0),
            (int) ($row['last_duration_ms'] ?? 0),
            is_string($row['locked_until'] ?? null) ? $row['locked_until'] : null,
            (int) ($row['consecutive_failures'] ?? 0),
            (int) ($row['runs'] ?? 0),
        );
    }

    public static function never(ScheduledTask $task): self
    {
        return new self($task, null, 'never', '', 0, 0, null, 0, 0);
    }

    /**
     * La tâche a-t-elle atteint son intervalle minimal ?
     */
    public function isDue(string $now): bool
    {
        if ($this->lastRunAt === null) {
            return true;
        }

        return self::minutesBetween($this->lastRunAt, $now) >= $this->task->intervalMinutes();
    }

    /**
     * La tâche accuse-t-elle un retard anormal ?
     *
     * Une tâche jamais exécutée n'est pas « en retard » : elle est
     * simplement inconnue du planificateur, ce qui arrive juste après une
     * installation et ne dit rien de la santé du cron.
     */
    public function isStale(string $now): bool
    {
        if ($this->lastRunAt === null) {
            return false;
        }

        return self::minutesBetween($this->lastRunAt, $now) >= $this->task->staleAfterMinutes();
    }

    public function isLocked(string $now): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > $now;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->task->value,
            'label_key' => $this->task->labelKey(),
            'interval_minutes' => $this->task->intervalMinutes(),
            'last_run_at' => $this->lastRunAt,
            'last_status' => $this->lastStatus,
            'last_detail' => $this->lastDetail,
            'last_count' => $this->lastCount,
            'last_duration_ms' => $this->lastDurationMs,
            'consecutive_failures' => $this->consecutiveFailures,
            'runs' => $this->runs,
        ];
    }

    public static function minutesBetween(string $from, string $to): int
    {
        $zone = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $from, $zone);
        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $to, $zone);

        if ($start === false || $end === false) {
            // Un horodatage illisible ne doit pas empêcher la tâche de
            // tourner : mieux vaut une exécution de trop qu'une tâche
            // définitivement bloquée par une ligne corrompue.
            return PHP_INT_MAX;
        }

        return intdiv(max(0, $end->getTimestamp() - $start->getTimestamp()), 60);
    }
}
