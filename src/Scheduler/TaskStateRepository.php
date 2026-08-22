<?php

declare(strict_types=1);

namespace SecondStay\Scheduler;

use SecondStay\Database\Database;

/**
 * Persistance de l'état des tâches périodiques.
 */
final class TaskStateRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function state(ScheduledTask $task): TaskState
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `scheduled_task` WHERE `code` = :code',
            ['code' => $task->value]
        );

        return $row === null ? TaskState::never($task) : TaskState::fromRow($task, $row);
    }

    /**
     * État de toutes les tâches connues du produit, y compris celles qui
     * n'ont jamais tourné : une tâche absente de la table est une tâche
     * jamais exécutée, pas une tâche inexistante.
     *
     * @return list<TaskState>
     */
    public function all(): array
    {
        $rows = [];
        foreach ($this->database->fetchAll('SELECT * FROM `scheduled_task`') as $row) {
            $code = $row['code'] ?? null;
            if (is_string($code)) {
                $rows[$code] = $row;
            }
        }

        $states = [];
        foreach (ScheduledTask::all() as $task) {
            $states[] = isset($rows[$task->value])
                ? TaskState::fromRow($task, $rows[$task->value])
                : TaskState::never($task);
        }

        return $states;
    }

    /**
     * Prend le verrou d'exécution, si personne ne le détient.
     *
     * Le verrou est pris par un `UPDATE` conditionnel : c'est la seule
     * primitive atomique disponible sans dépendance supplémentaire, et elle
     * suffit — deux appels cron qui se chevauchent voient l'un des deux
     * repartir sans rien faire, au lieu de relever deux fois la même boîte.
     *
     * @return bool vrai si le verrou a été obtenu
     */
    public function claim(ScheduledTask $task, string $now, string $lockedUntil): bool
    {
        $this->ensureRow($task);

        $affected = $this->database->execute(
            'UPDATE `scheduled_task` SET `locked_until` = :until '
            . 'WHERE `code` = :code AND (`locked_until` IS NULL OR `locked_until` <= :now)',
            ['until' => $lockedUntil, 'code' => $task->value, 'now' => $now]
        )->rowCount();

        return $affected === 1;
    }

    public function release(ScheduledTask $task, string $now, TaskOutcome $outcome, int $durationMs): void
    {
        $failures = $outcome->isError() ? $this->state($task)->consecutiveFailures + 1 : 0;

        $this->database->execute(
            'UPDATE `scheduled_task` SET `last_run_at` = :run, `last_status` = :status, '
            . '`last_detail` = :detail, `last_count` = :count, `last_duration_ms` = :duration, '
            . '`locked_until` = NULL, '
            . '`consecutive_failures` = :failures, `runs` = `runs` + 1 WHERE `code` = :code',
            [
                'run' => $now,
                'status' => $outcome->status,
                'detail' => mb_substr($outcome->detail, 0, 128),
                'count' => $outcome->count,
                'duration' => max(0, $durationMs),
                'failures' => min(65535, $failures),
                'code' => $task->value,
            ]
        );
    }

    /**
     * Horodatage de la dernière exécution, toutes tâches confondues, quel
     * qu'en soit le résultat.
     *
     * C'est bien la dernière **exécution** et non la dernière réussite : la
     * question posée est « le cron passe-t-il ? », à laquelle une tâche qui
     * échoue répond oui. « Telle tâche est-elle en bonne santé ? » est une
     * autre question, et les diagnostics la posent séparément — les confondre
     * afficherait un cron mort sur une installation dont une seule tâche
     * échoue en boucle.
     */
    public function lastRunAt(): ?string
    {
        $value = $this->database->fetchValue(
            'SELECT MAX(`last_run_at`) FROM `scheduled_task` WHERE `last_status` <> :never',
            ['never' => 'never']
        );

        return is_string($value) ? $value : null;
    }

    private function ensureRow(ScheduledTask $task): void
    {
        $this->database->execute(
            'INSERT INTO `scheduled_task` (`code`) VALUES (:code) '
            . 'ON DUPLICATE KEY UPDATE `code` = `code`',
            ['code' => $task->value]
        );
    }
}
