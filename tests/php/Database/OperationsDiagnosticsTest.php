<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Backup\BackupService;
use SecondStay\Diagnostics\DiagnosticResult;
use SecondStay\Diagnostics\DiagnosticStatus;
use SecondStay\Diagnostics\OperationsDiagnostics;
use SecondStay\Llm\FakeLlmProvider;
use SecondStay\Llm\LlmProvider;
use SecondStay\Llm\NullLlmProvider;
use SecondStay\Logging\Logger;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Payment\FakePaymentProvider;
use SecondStay\Payment\NullPaymentProvider;
use SecondStay\Payment\PaymentProvider;
use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\TaskOutcome;
use SecondStay\Scheduler\TaskStateRepository;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;
use SecondStay\Update\FakeReleaseProvider;
use SecondStay\Update\UpdateService;

/**
 * Diagnostics paiement, IA, cron, sauvegarde et mise à jour
 * (SPECIFICATIONS.md §18).
 *
 * L'exigence tient en une phrase : ces contrôles doivent dire la vérité sur
 * l'installation **sans un seul appel sortant**. Une page de diagnostics qui
 * interroge le fournisseur de paiement, le modèle et GitHub à chaque visite
 * devient lente, puis inutilisée, puis rouge le jour où l'un des trois est en
 * panne — alors que l'installation, elle, va bien.
 */
final class OperationsDiagnosticsTest extends DatabaseTestCase
{
    private SettingsService $settings;

    private TaskStateRepository $tasks;

    private BackupService $backups;

    private UpdateService $updates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );

        $this->tasks = new TaskStateRepository($this->database);

        $maintenance = new MaintenanceMode($this->storagePath . '/maintenance.json');
        $this->backups = new BackupService($this->database, $this->paths, $maintenance, '0.15.0');
        $this->updates = new UpdateService(
            new FakeReleaseProvider(),
            $this->paths,
            $this->database,
            $this->backups,
            $maintenance,
            new Logger($this->storagePath . '/logs'),
        );
    }

    // --- Paiement -------------------------------------------------------------------

    public function testNoPaymentProviderIsAChoiceNotAFailure(): void
    {
        $this->settings->setMany(['payment.provider' => 'none']);

        $result = $this->find('payment_provider');

        self::assertSame(DiagnosticStatus::NotApplicable, $result->status);
        self::assertSame('diagnostics.payment.disabled', $result->messageKey);
    }

    /**
     * Choisir un fournisseur puis oublier sa clé est en revanche une panne :
     * le parcours de réservation demandera un paiement qui ne partira pas.
     */
    public function testAProviderWithoutItsKeyIsAnError(): void
    {
        $this->settings->setMany(['payment.provider' => 'mollie']);

        $result = $this->find('payment_provider', new NullPaymentProvider());

        self::assertSame(DiagnosticStatus::Error, $result->status);
        self::assertSame('diagnostics.payment.not_configured', $result->messageKey);
    }

    public function testAConfiguredProviderIsReportedByNameWithoutItsKey(): void
    {
        $this->settings->setMany([
            'payment.provider' => 'mollie',
            'payment.mollie_api_key' => 'test_secret_key_qui_ne_doit_jamais_sortir',
        ]);

        $result = $this->find('payment_provider', new FakePaymentProvider());

        self::assertSame(DiagnosticStatus::Ok, $result->status);
        self::assertStringNotContainsString(
            'test_secret',
            implode(' ', array_map(strval(...), $result->details)),
        );
    }

    // --- Contenu local --------------------------------------------------------------

    public function testLocalContentEnabledWithoutAModelIsAnError(): void
    {
        $this->settings->setMany(['llm.enabled' => '1']);

        $result = $this->find('llm_provider', llm: new NullLlmProvider());

        self::assertSame(DiagnosticStatus::Error, $result->status);
    }

    public function testLocalContentDisabledIsNotApplicable(): void
    {
        self::assertSame(DiagnosticStatus::NotApplicable, $this->find('llm_provider')->status);
    }

    public function testLocalContentReadyIsReported(): void
    {
        $this->settings->setMany(['llm.enabled' => '1']);

        self::assertSame(DiagnosticStatus::Ok, $this->find('llm_provider', llm: new FakeLlmProvider())->status);
    }

    // --- Cron -----------------------------------------------------------------------

    /**
     * Une installation neuve n'a pas un cron en panne : elle a un cron dont
     * personne n'a encore ajouté la ligne. La nuance porte l'action à mener.
     */
    public function testACronThatNeverRanIsAWarningNotAnError(): void
    {
        $result = $this->find('scheduler_cron');

        self::assertSame(DiagnosticStatus::Warning, $result->status);
        self::assertSame('diagnostics.scheduler.never', $result->messageKey);
    }

    /**
     * Les deux lignes sont rendues même quand il n'y a rien à dire : une ligne
     * qui disparaît de l'écran se confond avec un contrôle qui n'existe pas,
     * et l'on ne cherche pas ce qu'on ne voit pas.
     */
    public function testBothSchedulerChecksAreAlwaysRenderedIncludingOnAFreshInstallation(): void
    {
        $tasks = $this->find('scheduler_tasks');

        self::assertSame(DiagnosticStatus::NotApplicable, $tasks->status);
        self::assertSame('diagnostics.scheduler.never', $tasks->messageKey);
    }

    public function testARecentRunReportsTheCronAsAlive(): void
    {
        $this->record(ScheduledTask::BookingHolds, TaskOutcome::ok(), gmdate('Y-m-d H:i:s'));

        $result = $this->find('scheduler_cron');

        self::assertSame(DiagnosticStatus::Ok, $result->status);
        self::assertSame('diagnostics.scheduler.running', $result->messageKey);
    }

    public function testASilentCronIsAnError(): void
    {
        $this->record(
            ScheduledTask::BookingHolds,
            TaskOutcome::ok(),
            gmdate('Y-m-d H:i:s', time() - 4 * 3600)
        );

        $result = $this->find('scheduler_cron');

        self::assertSame(DiagnosticStatus::Error, $result->status);
        self::assertSame('diagnostics.scheduler.silent', $result->messageKey);
    }

    /**
     * Un cron qui passe alors qu'une tâche échoue en boucle ne doit pas
     * s'afficher intégralement vert : les deux questions sont distinctes.
     */
    public function testAFailingTaskIsReportedSeparatelyFromTheCronItself(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->record(ScheduledTask::BookingHolds, TaskOutcome::ok(), $now);
        $this->record(ScheduledTask::Backup, TaskOutcome::error('scheduler.detail.exception'), $now);

        self::assertSame(DiagnosticStatus::Ok, $this->find('scheduler_cron')->status);

        $tasks = $this->find('scheduler_tasks');
        self::assertSame(DiagnosticStatus::Error, $tasks->status);
        self::assertSame('diagnostics.scheduler.failing', $tasks->messageKey);
        self::assertSame('backup', $tasks->details['detail'] ?? '');
    }

    public function testAnOverdueTaskIsOnlyAWarning(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->record(ScheduledTask::BookingHolds, TaskOutcome::ok(), $now);
        $this->record(
            ScheduledTask::CalendarImport,
            TaskOutcome::ok(),
            gmdate('Y-m-d H:i:s', time() - 5 * 3600)
        );

        $tasks = $this->find('scheduler_tasks');
        self::assertSame(DiagnosticStatus::Warning, $tasks->status);
        self::assertSame('diagnostics.scheduler.late', $tasks->messageKey);
    }

    // --- Sauvegardes ----------------------------------------------------------------

    public function testNoBackupIsAWarning(): void
    {
        $result = $this->find('backup_state');

        self::assertSame(DiagnosticStatus::Warning, $result->status);
        self::assertSame('diagnostics.backup.none', $result->messageKey);
    }

    public function testAFreshBackupIsReportedWithItsCountAndSize(): void
    {
        $this->backups->create(false);

        $result = $this->find('backup_state');

        self::assertSame(DiagnosticStatus::Ok, $result->status);
        self::assertStringStartsWith('1 — ', (string) ($result->details['detail'] ?? ''));
    }

    /**
     * Une sauvegarde n'a de valeur qu'au moment où on en a besoin, bien après
     * le jour où elle a cessé de se faire : c'est son âge qui compte.
     */
    public function testAnOldBackupIsReportedAsStale(): void
    {
        $created = $this->backups->create(false);
        touch($created['path'], time() - (OperationsDiagnostics::BACKUP_MAX_AGE_DAYS + 2) * 86400);

        $result = $this->find('backup_state');

        self::assertSame(DiagnosticStatus::Warning, $result->status);
        self::assertSame('diagnostics.backup.stale', $result->messageKey);
    }

    // --- Mise à jour ----------------------------------------------------------------

    public function testTheUpdateCheckReportsVersionAndChannelWithoutCallingOut(): void
    {
        $this->settings->setMany(['update.channel' => 'prerelease', 'update.auto_install' => '1']);

        $result = $this->find('update_channel');

        self::assertSame(DiagnosticStatus::Ok, $result->status);
        self::assertSame('diagnostics.update.automatic', $result->messageKey);
        self::assertStringContainsString('prerelease', (string) ($result->details['detail'] ?? ''));
    }

    // --- Outils ---------------------------------------------------------------------

    private function record(ScheduledTask $task, TaskOutcome $outcome, string $at): void
    {
        $this->tasks->claim($task, $at, gmdate('Y-m-d H:i:s', strtotime($at . ' UTC') - 1));
        $this->tasks->release($task, $at, $outcome, 1);
    }

    private function find(
        string $id,
        ?PaymentProvider $payment = null,
        ?LlmProvider $llm = null,
    ): DiagnosticResult {
        $diagnostics = new OperationsDiagnostics(
            $this->settings,
            $payment ?? new NullPaymentProvider(),
            $llm ?? new NullLlmProvider(),
            $this->tasks,
            $this->backups,
            $this->updates,
        );

        foreach ($diagnostics() as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        self::fail('Diagnostic absent : ' . $id);
    }
}
