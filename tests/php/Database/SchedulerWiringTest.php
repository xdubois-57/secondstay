<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Booking\BookingStatus;
use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\SchedulerFactory;
use SecondStay\Scheduler\TaskStateRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Update\FakeReleaseProvider;
use SecondStay\Update\ReleaseProvider;
use SecondStay\Update\UpdateService;
use SecondStay\Tests\Support\InstalledAppTestCase;

/**
 * Branchement des tâches périodiques sur les services du produit
 * (ARCHITECTURE.md §23).
 *
 * `SchedulerTest` vérifie l'ordonnanceur : verrou, isolement des échecs,
 * honnêteté de l'état. Ce test-ci vérifie autre chose, et c'est ce qui casse
 * en silence : que chaque tâche est **réellement branchée** sur le service
 * qu'elle prétend appeler. Une tâche non enregistrée ne produit aucune erreur —
 * elle ne fait simplement rien, indéfiniment, pendant que l'écran
 * d'exploitation affiche une liste rassurante.
 */
final class SchedulerWiringTest extends InstalledAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Le contrôle de mise à jour interroge GitHub : la suite de tests ne
        // doit dépendre d'aucun service en ligne (TESTING.md §7). Le
        // fournisseur factice remplace la seule sortie réseau du passage
        // complet ; tout le reste des tâches est réellement exécuté.
        $this->container->set(
            ReleaseProvider::class,
            static fn (): ReleaseProvider => new FakeReleaseProvider()
        );
        $this->container->forget(UpdateService::class);
    }

    public function testEveryDeclaredTaskHasAHandler(): void
    {
        $scheduler = SchedulerFactory::build($this->container);

        foreach (ScheduledTask::all() as $task) {
            self::assertTrue($scheduler->handles($task), 'Tâche sans traitement : ' . $task->value);
        }
    }

    /**
     * Sur une installation neuve, un passage complet du cron doit se terminer
     * sans échec : ce qui n'est pas configuré est ignoré, pas cassé.
     */
    public function testAFullRunOnAFreshInstallationNeverFails(): void
    {
        $results = SchedulerFactory::build($this->container)->runDue();

        self::assertNotSame([], $results, 'Un premier passage doit exécuter les tâches dues.');

        foreach ($results as $result) {
            self::assertNotSame('error', $result['status'], $result['task'] . ' : ' . $result['detail']);
        }
    }

    /**
     * Un module désactivé se lit « ignoré », jamais « réussi » : sinon le
     * propriétaire croit relever son courrier alors que rien ne le relève.
     */
    public function testDisabledModulesAreSkippedNotReportedAsSuccessful(): void
    {
        $scheduler = SchedulerFactory::build($this->container);

        foreach ([ScheduledTask::InboundMail, ScheduledTask::LocalContent] as $task) {
            $result = $scheduler->runNow($task);

            self::assertSame('skipped', $result['status'], $task->value);
            self::assertSame('scheduler.detail.disabled', $result['detail']);
        }
    }

    public function testWithoutAnyExternalCalendarTheImportIsSkipped(): void
    {
        $result = SchedulerFactory::build($this->container)->runNow(ScheduledTask::CalendarImport);

        self::assertSame('skipped', $result['status']);
        self::assertSame('scheduler.detail.no_calendar', $result['detail']);
    }

    /**
     * Le verrou de réservation abandonné est le seul retard qui se voie du
     * côté public : une nuit verrouillée par un panier oublié reste
     * invendable tant que personne ne la libère. C'était le cas avant le
     * planificateur : la méthode existait, et personne ne l'appelait.
     */
    public function testAnAbandonedHoldIsActuallyReleasedByTheTask(): void
    {
        $id = $this->database->insert('booking', [
            'reference' => 'HOLD-TEST',
            'user_id' => null,
            'status' => BookingStatus::Hold->value,
            'arrival' => '2099-04-10',
            'departure' => '2099-04-17',
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => 'panier@example.test',
            'guest_name' => 'Panier abandonné',
            'guest_phone' => '',
            'total_cents' => 50_000,
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
            'created_at' => gmdate('Y-m-d H:i:s', time() - 7200),
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 7200),
        ]);
        $this->database->insert('booking_night', ['day' => '2099-04-10', 'booking_id' => $id]);

        $result = SchedulerFactory::build($this->container)->runNow(ScheduledTask::BookingHolds);

        self::assertSame('ok', $result['status']);
        self::assertSame('scheduler.detail.holds_released', $result['detail']);
        self::assertSame(1, $result['count']);

        // La nuit est réellement rendue à la vente.
        self::assertNull($this->database->fetchOne(
            'SELECT `day` FROM `booking_night` WHERE `booking_id` = :id',
            ['id' => $id]
        ));
    }

    public function testTheBackupTaskHonoursItsSetting(): void
    {
        $settings = $this->container->get(SettingsService::class);
        $settings->setMany(['backup.auto_enabled' => '0']);

        $scheduler = SchedulerFactory::build($this->container);
        self::assertSame('skipped', $scheduler->runNow(ScheduledTask::Backup)['status']);

        $settings->setMany(['backup.auto_enabled' => '1', 'backup.include_media' => '0']);

        $result = SchedulerFactory::build($this->container)->runNow(ScheduledTask::Backup);
        self::assertSame('ok', $result['status']);
        self::assertSame('scheduler.detail.backup_done', $result['detail']);

        $created = glob($this->container->get(\SecondStay\Backup\BackupService::class)->directory() . '/*.zip') ?: [];
        self::assertCount(1, $created, 'La tâche doit avoir réellement écrit une archive.');
    }

    /**
     * Un passage cron ne réexécute pas ce qui vient de tourner : sur un
     * hébergement qui appelle le script toutes les cinq minutes, une
     * sauvegarde quotidienne doit rester quotidienne.
     */
    public function testASecondPassRunsNothing(): void
    {
        $scheduler = SchedulerFactory::build($this->container);
        $scheduler->runDue();

        self::assertSame([], SchedulerFactory::build($this->container)->runDue());
    }

    public function testTheStateSurvivesForTheDiagnosticsToRead(): void
    {
        SchedulerFactory::build($this->container)->runNow(ScheduledTask::Retention);

        $states = new TaskStateRepository($this->database);

        self::assertNotNull($states->lastRunAt());
        self::assertSame('ok', $states->state(ScheduledTask::Retention)->lastStatus);
    }
}
