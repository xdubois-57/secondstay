<?php

declare(strict_types=1);

namespace SecondStay\Scheduler;

use SecondStay\Audit\AuditTrail;
use SecondStay\Logging\Logger;
use Throwable;

/**
 * Exécute les tâches périodiques dues (ARCHITECTURE.md §23).
 *
 * Le produit ne fait pas tourner de worker : une entrée cron unique appelle le
 * planificateur, qui décide lui-même de ce qui est dû. Le calendrier vit donc
 * dans le produit, pas dans la table cron de l'hébergeur — un propriétaire qui
 * déplace son installation n'a qu'une ligne à recopier, et le produit reste
 * correct que le cron passe toutes les cinq minutes ou toutes les heures.
 *
 * Trois propriétés tiennent l'ensemble :
 *
 * 1. **une tâche qui échoue n'arrête pas les autres.** Une boîte IMAP
 *    injoignable ne doit pas empêcher la sauvegarde de la nuit ;
 * 2. **une tâche n'est jamais exécutée deux fois en parallèle.** Le verrou est
 *    pris en base, parce que deux appels cron peuvent se chevaucher ;
 * 3. **rien n'est jamais exécuté silencieusement.** Chaque passage laisse un
 *    état lisible, y compris lorsqu'il n'a rien fait.
 */
final class Scheduler
{
    /**
     * Longueur minimale du jeton de déclenchement HTTP.
     *
     * La valeur vit ici et non dans le contrôleur : la validation du réglage
     * et la vérification à l'appel doivent parler du même seuil, et un
     * validateur de réglages n'a rien à savoir des contrôleurs.
     */
    public const MINIMUM_TOKEN_LENGTH = 32;

    /** @var array<string, callable(): TaskOutcome> */
    private array $handlers = [];

    public function __construct(
        private readonly TaskStateRepository $states,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * @param callable(): TaskOutcome $handler
     */
    public function register(ScheduledTask $task, callable $handler): void
    {
        $this->handlers[$task->value] = $handler;
    }

    public function handles(ScheduledTask $task): bool
    {
        return isset($this->handlers[$task->value]);
    }

    /**
     * Exécute toutes les tâches dues.
     *
     * @return list<array{task: string, status: string, detail: string, count: int, duration_ms: int}>
     */
    public function runDue(?string $now = null): array
    {
        $now ??= gmdate('Y-m-d H:i:s');
        $results = [];

        foreach ($this->states->all() as $state) {
            if (!$this->handles($state->task) || !$state->isDue($now) || $state->isLocked($now)) {
                continue;
            }

            $results[] = $this->execute($state->task, $now);
        }

        return $results;
    }

    /**
     * Exécute une tâche à la demande, sans attendre son intervalle.
     *
     * Le verrou reste respecté : « forcer » veut dire ignorer l'horloge, pas
     * doubler une exécution déjà en cours.
     *
     * @return array{task: string, status: string, detail: string, count: int, duration_ms: int}
     */
    public function runNow(ScheduledTask $task, ?string $now = null): array
    {
        $now ??= gmdate('Y-m-d H:i:s');

        if (!$this->handles($task)) {
            return [
                'task' => $task->value,
                'status' => TaskOutcome::SKIPPED,
                'detail' => 'scheduler.detail.no_handler',
                'count' => 0,
                'duration_ms' => 0,
            ];
        }

        if ($this->states->state($task)->isLocked($now)) {
            return [
                'task' => $task->value,
                'status' => TaskOutcome::SKIPPED,
                'detail' => 'scheduler.detail.locked',
                'count' => 0,
                'duration_ms' => 0,
            ];
        }

        return $this->execute($task, $now);
    }

    /**
     * @return list<TaskState>
     */
    public function states(): array
    {
        return $this->states->all();
    }

    /**
     * @return array{task: string, status: string, detail: string, count: int, duration_ms: int}
     */
    private function execute(ScheduledTask $task, string $now): array
    {
        $lockedUntil = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + $task->lockMinutes() * 60);

        if (!$this->states->claim($task, $now, $lockedUntil)) {
            // Un autre passage cron a pris le verrou entre-temps.
            return [
                'task' => $task->value,
                'status' => TaskOutcome::SKIPPED,
                'detail' => 'scheduler.detail.locked',
                'count' => 0,
                'duration_ms' => 0,
            ];
        }

        $started = hrtime(true);

        try {
            $outcome = ($this->handlers[$task->value])();
        } catch (Throwable $exception) {
            // Le message d'exception n'est pas rendu tel quel à l'écran : il
            // peut porter un hôte, un chemin ou un identifiant. Il part au
            // journal, qui est déjà assaini, et l'écran reçoit une clé.
            $this->logger->error('scheduler', 'Tâche périodique en échec', [
                'task' => $task->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $outcome = TaskOutcome::error('scheduler.detail.exception');
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        $this->states->release($task, gmdate('Y-m-d H:i:s'), $outcome, $durationMs);

        if ($outcome->isError()) {
            $this->audit?->record('scheduler.failed', 'scheduled_task', $task->value, null, [
                'detail' => $outcome->detail,
            ], null, 'scheduler');
        } else {
            $this->logger->info('scheduler', 'Tâche périodique exécutée', [
                'task' => $task->value,
                'status' => $outcome->status,
                'duration_ms' => $durationMs,
            ]);
        }

        return [
            'task' => $task->value,
            'status' => $outcome->status,
            'detail' => $outcome->detail,
            'count' => $outcome->count,
            'duration_ms' => $durationMs,
        ];
    }
}
