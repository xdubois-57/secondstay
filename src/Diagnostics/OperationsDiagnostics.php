<?php

declare(strict_types=1);

namespace SecondStay\Diagnostics;

use SecondStay\Backup\BackupService;
use SecondStay\Installer\RequirementChecker;
use SecondStay\Llm\LlmProvider;
use SecondStay\Payment\PaymentProvider;
use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\TaskState;
use SecondStay\Scheduler\TaskStateRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Update\UpdateService;
use Throwable;

/**
 * Contrôles paiement, IA, tâches périodiques, sauvegarde et mise à jour
 * (SPECIFICATIONS.md §18).
 *
 * Deux règles gouvernent ces contrôles, comme les précédents :
 *
 * 1. **aucun appel réseau à l'affichage.** Ouvrir la page de diagnostics ne
 *    doit ni interroger le fournisseur de paiement, ni le modèle, ni GitHub.
 *    Un diagnostic qui ralentit la page finit par ne plus être consulté, et
 *    une page qui parle au monde extérieur à chaque visite est une page qui
 *    tombe quand le monde extérieur tombe. Ce qui est vérifié ici, c'est donc
 *    l'état **local** : une configuration présente, une trace d'exécution,
 *    une archive sur le disque ;
 * 2. **aucun secret n'apparaît.** On dit qu'une clé est enregistrée, jamais ce
 *    qu'elle vaut.
 */
final class OperationsDiagnostics
{
    public const CATEGORY = 'operations';

    /** Âge maximal d'une sauvegarde avant alerte, en jours. */
    public const BACKUP_MAX_AGE_DAYS = 8;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PaymentProvider $payment,
        private readonly LlmProvider $llm,
        private readonly TaskStateRepository $tasks,
        private readonly BackupService $backups,
        private readonly UpdateService $updates,
    ) {
    }

    /**
     * @return list<DiagnosticResult>
     */
    public function __invoke(): array
    {
        return array_merge(
            [$this->checkPayment()],
            [$this->checkLlm()],
            $this->checkScheduler(),
            [$this->checkBackup()],
            [$this->checkUpdate()],
        );
    }

    /**
     * Le fournisseur de paiement décide si une réservation peut se confirmer
     * toute seule : sans lui, chaque acompte demande une vérification
     * manuelle. Ce n'est pas une erreur — c'est un choix — mais il doit se
     * voir.
     */
    private function checkPayment(): DiagnosticResult
    {
        $provider = $this->settings->string('payment.provider');

        if ($provider === '' || $provider === 'none') {
            return new DiagnosticResult(
                'payment_provider',
                self::CATEGORY,
                DiagnosticStatus::NotApplicable,
                'diagnostics.payment.disabled',
            );
        }

        if (!$this->payment->isConfigured()) {
            return new DiagnosticResult(
                'payment_provider',
                self::CATEGORY,
                DiagnosticStatus::Error,
                'diagnostics.payment.not_configured',
                ['detail' => $provider],
            );
        }

        return new DiagnosticResult(
            'payment_provider',
            self::CATEGORY,
            DiagnosticStatus::Ok,
            'diagnostics.payment.configured',
            ['detail' => $this->payment->name()],
        );
    }

    private function checkLlm(): DiagnosticResult
    {
        if (!$this->settings->bool('llm.enabled')) {
            return new DiagnosticResult(
                'llm_provider',
                self::CATEGORY,
                DiagnosticStatus::NotApplicable,
                'diagnostics.llm.disabled',
            );
        }

        if (!$this->llm->isConfigured()) {
            return new DiagnosticResult(
                'llm_provider',
                self::CATEGORY,
                DiagnosticStatus::Error,
                'diagnostics.llm.not_configured',
            );
        }

        return new DiagnosticResult(
            'llm_provider',
            self::CATEGORY,
            DiagnosticStatus::Ok,
            'diagnostics.llm.ready',
            ['detail' => $this->llm->name()],
        );
    }

    /**
     * Santé du cron.
     *
     * Deux questions distinctes, deux contrôles : « le cron passe-t-il ? » et
     * « une tâche est-elle en souffrance ? ». Confondre les deux donnerait un
     * diagnostic vert sur une installation dont une seule tâche échoue en
     * boucle, ou rouge sur une installation neuve dont le cron n'a simplement
     * pas encore eu l'occasion de tourner.
     *
     * @return list<DiagnosticResult>
     */
    private function checkScheduler(): array
    {
        // Les deux contrôles sont **toujours** rendus, y compris quand il n'y
        // a rien à dire. Une ligne qui disparaît de l'écran de diagnostics
        // n'attire pas l'attention : elle se confond avec un contrôle qui
        // n'existe pas, et l'on ne cherche pas ce qu'on ne voit pas.
        try {
            $states = $this->tasks->all();
            $lastRun = $this->tasks->lastRunAt();
        } catch (Throwable) {
            return [
                $this->schedulerResult('scheduler_cron', DiagnosticStatus::Warning, 'diagnostics.scheduler.unknown'),
                $this->schedulerResult('scheduler_tasks', DiagnosticStatus::Warning, 'diagnostics.scheduler.unknown'),
            ];
        }

        $now = gmdate('Y-m-d H:i:s');

        if ($lastRun === null) {
            // Installation neuve : la ligne cron n'a pas encore été posée.
            // Ce n'est pas une panne, et aucune tâche n'est en souffrance —
            // elles n'ont simplement jamais eu l'occasion de tourner.
            return [
                $this->schedulerResult('scheduler_cron', DiagnosticStatus::Warning, 'diagnostics.scheduler.never'),
                $this->schedulerResult(
                    'scheduler_tasks',
                    DiagnosticStatus::NotApplicable,
                    'diagnostics.scheduler.never'
                ),
            ];
        }

        // Le cron est considéré comme vivant tant qu'il a servi la tâche la
        // plus fréquente dans sa fenêtre de retard.
        $tolerance = ScheduledTask::BookingHolds->staleAfterMinutes();
        $silence = TaskState::minutesBetween($lastRun, $now);

        $stale = [];
        $failing = [];
        foreach ($states as $state) {
            if ($state->isStale($now)) {
                $stale[] = $state->task->value;
            }
            if ($state->lastStatus === 'error') {
                $failing[] = $state->task->value;
            }
        }

        return [
            $this->schedulerResult(
                'scheduler_cron',
                $silence <= $tolerance ? DiagnosticStatus::Ok : DiagnosticStatus::Error,
                $silence <= $tolerance ? 'diagnostics.scheduler.running' : 'diagnostics.scheduler.silent',
                $lastRun,
            ),
            $this->schedulerResult(
                'scheduler_tasks',
                $failing !== [] ? DiagnosticStatus::Error
                    : ($stale !== [] ? DiagnosticStatus::Warning : DiagnosticStatus::Ok),
                $failing !== [] ? 'diagnostics.scheduler.failing'
                    : ($stale !== [] ? 'diagnostics.scheduler.late' : 'diagnostics.scheduler.tasks_ok'),
                implode(', ', $failing !== [] ? $failing : $stale),
            ),
        ];
    }

    private function schedulerResult(
        string $id,
        DiagnosticStatus $status,
        string $messageKey,
        string $detail = '',
    ): DiagnosticResult {
        return new DiagnosticResult($id, self::CATEGORY, $status, $messageKey, ['detail' => $detail]);
    }

    /**
     * Une sauvegarde n'a de valeur qu'au moment où on en a besoin, c'est-à-dire
     * bien après le jour où elle a cessé de se faire. C'est donc son **âge**
     * qui est contrôlé, pas seulement son existence.
     */
    private function checkBackup(): DiagnosticResult
    {
        try {
            $backups = $this->backups->list();
            $usage = $this->backups->diskUsage();
        } catch (Throwable) {
            return new DiagnosticResult(
                'backup_state',
                self::CATEGORY,
                DiagnosticStatus::Warning,
                'diagnostics.backup.unknown',
            );
        }

        if ($backups === []) {
            return new DiagnosticResult(
                'backup_state',
                self::CATEGORY,
                DiagnosticStatus::Warning,
                'diagnostics.backup.none',
            );
        }

        $newest = strtotime($backups[0]['created_at']);
        $ageDays = $newest === false ? PHP_INT_MAX : intdiv(max(0, time() - $newest), 86400);

        return new DiagnosticResult(
            'backup_state',
            self::CATEGORY,
            $ageDays <= self::BACKUP_MAX_AGE_DAYS ? DiagnosticStatus::Ok : DiagnosticStatus::Warning,
            $ageDays <= self::BACKUP_MAX_AGE_DAYS ? 'diagnostics.backup.fresh' : 'diagnostics.backup.stale',
            ['detail' => count($backups) . ' — ' . RequirementChecker::humanBytes($usage)],
        );
    }

    /**
     * Version installée et canal choisi. Le contrôle de disponibilité d'une
     * nouvelle version appartient à la tâche périodique : le savoir demande un
     * appel réseau, et cette page n'en fait pas.
     */
    private function checkUpdate(): DiagnosticResult
    {
        $autoInstall = $this->settings->bool('update.auto_install');
        $channel = $this->settings->string('update.channel');

        return new DiagnosticResult(
            'update_channel',
            self::CATEGORY,
            DiagnosticStatus::Ok,
            $autoInstall ? 'diagnostics.update.automatic' : 'diagnostics.update.manual',
            ['detail' => $this->updates->currentVersion() . ' — ' . ($channel === '' ? 'stable' : $channel)],
        );
    }
}
