<?php

declare(strict_types=1);

namespace SecondStay\Scheduler;

use SecondStay\Backup\BackupService;
use SecondStay\Booking\BookingService;
use SecondStay\Calendar\ExternalCalendarService;
use SecondStay\Core\Container;
use SecondStay\Imap\InboundMailService;
use SecondStay\Logging\Logger;
use SecondStay\LocalContent\LocalContentService;
use SecondStay\Notification\StayReminderService;
use SecondStay\Privacy\RetentionService;
use SecondStay\Settings\SettingsService;
use SecondStay\Update\UpdateService;
use Throwable;

/**
 * Branche les tâches périodiques sur les services du produit.
 *
 * Les gestionnaires sont des fermetures : le planificateur ne construit un
 * service que lorsqu'il exécute réellement la tâche correspondante. Une relève
 * IMAP qui n'est pas due ne doit pas instancier de client IMAP, et un module
 * dont la configuration est incomplète ne doit pas empêcher les autres tâches
 * de tourner.
 *
 * Le détail rapporté est **toujours une clé de traduction**, jamais un message
 * brut : l'écran d'exploitation parle les quatre langues, et un message de
 * fournisseur peut porter un hôte ou un chemin.
 */
final class SchedulerFactory
{
    public static function build(Container $container): Scheduler
    {
        $scheduler = $container->get(Scheduler::class);

        self::registerBookingHolds($scheduler, $container);
        self::registerInboundMail($scheduler, $container);
        self::registerCalendarImport($scheduler, $container);
        self::registerLocalContent($scheduler, $container);
        self::registerStayReminders($scheduler, $container);
        self::registerRetention($scheduler, $container);
        self::registerBackup($scheduler, $container);
        self::registerUpdateCheck($scheduler, $container);

        return $scheduler;
    }

    private static function registerBookingHolds(Scheduler $scheduler, Container $container): void
    {
        $scheduler->register(ScheduledTask::BookingHolds, static function () use ($container): TaskOutcome {
            $released = $container->get(BookingService::class)->releaseExpiredHolds();

            return $released === 0
                ? TaskOutcome::ok('scheduler.detail.nothing')
                : TaskOutcome::ok('scheduler.detail.holds_released', $released);
        });
    }

    private static function registerInboundMail(Scheduler $scheduler, Container $container): void
    {
        $scheduler->register(ScheduledTask::InboundMail, static function () use ($container): TaskOutcome {
            $settings = $container->get(SettingsService::class);
            if (!$settings->bool('imap.enabled')) {
                return TaskOutcome::skipped('scheduler.detail.disabled');
            }

            $result = $container->get(InboundMailService::class)
                ->synchronise(max(1, $settings->int('imap.batch_size')));

            if (!$result['ok']) {
                return TaskOutcome::error('scheduler.detail.mailbox_unreachable');
            }

            return TaskOutcome::ok('scheduler.detail.mail_imported', $result['imported']);
        });
    }

    private static function registerCalendarImport(Scheduler $scheduler, Container $container): void
    {
        $scheduler->register(ScheduledTask::CalendarImport, static function () use ($container): TaskOutcome {
            $result = $container->get(ExternalCalendarService::class)->syncAll();

            if ($result['calendars'] === 0) {
                return TaskOutcome::skipped('scheduler.detail.no_calendar');
            }

            // Un flux muet ne libère aucune nuit, mais le propriétaire doit le
            // savoir : l'échec est remonté même si les autres flux ont réussi.
            if ($result['failed'] > 0) {
                return TaskOutcome::error('scheduler.detail.calendar_failed', $result['failed']);
            }

            return TaskOutcome::ok('scheduler.detail.calendar_events', $result['events']);
        });
    }

    private static function registerLocalContent(Scheduler $scheduler, Container $container): void
    {
        $scheduler->register(ScheduledTask::LocalContent, static function () use ($container): TaskOutcome {
            $service = $container->get(LocalContentService::class);
            if (!$service->isEnabled()) {
                return TaskOutcome::skipped('scheduler.detail.disabled');
            }

            $result = $service->refreshDue();

            if ($result['failed'] > 0) {
                return TaskOutcome::error('scheduler.detail.local_content_failed', $result['failed']);
            }

            return $result['stays'] === 0
                ? TaskOutcome::ok('scheduler.detail.nothing')
                : TaskOutcome::ok('scheduler.detail.local_content', $result['stays']);
        });
    }

    private static function registerStayReminders(Scheduler $scheduler, Container $container): void
    {
        $scheduler->register(ScheduledTask::StayReminders, static function () use ($container): TaskOutcome {
            $sent = $container->get(StayReminderService::class)->dispatch();
            $total = $sent['reminders'] + $sent['arrivals'] + $sent['departures'];

            return $total === 0
                ? TaskOutcome::ok('scheduler.detail.nothing')
                : TaskOutcome::ok('scheduler.detail.reminders_sent', $total);
        });
    }

    private static function registerRetention(Scheduler $scheduler, Container $container): void
    {
        $scheduler->register(ScheduledTask::Retention, static function () use ($container): TaskOutcome {
            $removed = array_sum($container->get(RetentionService::class)->purge());

            return $removed === 0
                ? TaskOutcome::ok('scheduler.detail.nothing')
                : TaskOutcome::ok('scheduler.detail.purged', (int) $removed);
        });
    }

    private static function registerBackup(Scheduler $scheduler, Container $container): void
    {
        $scheduler->register(ScheduledTask::Backup, static function () use ($container): TaskOutcome {
            $settings = $container->get(SettingsService::class);
            if (!$settings->bool('backup.auto_enabled')) {
                return TaskOutcome::skipped('scheduler.detail.disabled');
            }

            $service = $container->get(BackupService::class);
            $service->create($settings->bool('backup.include_media'), 'scheduler');
            // La rétention est appliquée dans la foulée : une sauvegarde
            // quotidienne sans rétention remplit le disque de l'hébergement en
            // quelques semaines, et la première victime serait le produit.
            $removed = $service->applyRetention($settings->int('backup.retention_count'));

            return TaskOutcome::ok('scheduler.detail.backup_done', count($removed));
        });
    }

    /**
     * Vérifie, mémorise le résultat pour la page des mises à jour, et
     * installe si l'administrateur l'a demandé (`update.auto_install`).
     *
     * L'installation automatique suit exactement le chemin du bouton :
     * empreinte, politique d'artefact, sauvegarde, retour arrière. Elle
     * n'existe pas pour contourner une seule de ces validations
     * (RELEASE.md §12).
     */
    private static function registerUpdateCheck(Scheduler $scheduler, Container $container): void
    {
        $scheduler->register(ScheduledTask::UpdateCheck, static function () use ($container): TaskOutcome {
            $settings = $container->get(SettingsService::class);
            $updates = $container->get(UpdateService::class);

            // Une mise à jour interrompue est défaite avant toute autre chose.
            $updates->recoverInterrupted();

            $result = $updates->refresh($settings->string('update.channel') === 'prerelease');

            if (!$result['available'] || $result['latest'] === null) {
                return TaskOutcome::ok('scheduler.detail.up_to_date');
            }

            if (!$settings->bool('update.auto_install')) {
                return TaskOutcome::ok('scheduler.detail.update_available');
            }

            try {
                $installed = $updates->install($result['latest'], 'scheduler');
            } catch (Throwable $throwable) {
                // Refusée avant d'avoir rien touché : empreinte, artefact,
                // téléchargement. Le site tourne sur la version en place.
                $container->get(Logger::class)->error('update', 'Installation automatique refusée', [
                    'reason' => $throwable->getMessage(),
                ]);

                return TaskOutcome::error('scheduler.detail.update_refused');
            }

            return $installed['installed']
                ? TaskOutcome::ok('scheduler.detail.update_installed')
                : TaskOutcome::error('scheduler.detail.update_rolled_back');
        });
    }
}
