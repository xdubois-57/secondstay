<?php

declare(strict_types=1);

/**
 * Periodic tasks (ARCHITECTURE.md §23).
 */

return [
    'title' => 'Scheduled tasks',
    'intro' => 'The product runs no permanent process: a single cron entry triggers everything that is due. Add it with your host, as often as it allows.',
    'command_path' => '/path/to/secondstay/src/Scheduler/cron.php',
    'never' => 'never run',
    'stale' => 'overdue',
    'every' => 'at most every {minutes} minutes',
    'column' => [
        'task' => 'Task',
        'last_run' => 'Last run',
        'result' => 'Result',
    ],
    'action' => [
        'run' => 'Run',
    ],
    'flash' => [
        'done' => 'Task completed.',
        'skipped' => 'Task not run: it is disabled or already running.',
        'failed' => 'The task failed. Details are in the log.',
    ],
    'status' => [
        'never' => 'never run',
        'ok' => 'succeeded',
        'skipped' => 'skipped',
        'error' => 'failed',
    ],
    'task' => [
        'booking_holds' => 'Release expired booking holds',
        'inbound_mail' => 'Fetch the dedicated mailbox',
        'calendar_import' => 'Synchronise external calendars',
        'local_content' => 'Generate local content',
        'stay_reminders' => 'Stay reminders, arrivals and departures',
        'retention' => 'Purge data past its retention',
        'backup' => 'Automatic backup',
        'update_check' => 'Check for updates',
    ],
    'detail' => [
        'nothing' => 'Nothing to do.',
        'disabled' => 'Feature disabled in the settings.',
        'no_handler' => 'No handler is wired to this task.',
        'locked' => 'A run is already in progress.',
        'exception' => 'Interrupted by an error; see the log.',
        'holds_released' => '{count} hold(s) released.',
        'mail_imported' => '{count} message(s) imported.',
        'mailbox_unreachable' => 'Mailbox unreachable.',
        'no_calendar' => 'No external calendar declared.',
        'calendar_failed' => '{count} feed(s) did not answer.',
        'calendar_events' => '{count} event(s) imported.',
        'local_content' => '{count} stay(s) refreshed.',
        'local_content_failed' => '{count} stay(s) could not be generated.',
        'reminders_sent' => '{count} notification(s) sent.',
        'purged' => '{count} record(s) purged.',
        'backup_done' => 'Backup created; {count} old one(s) removed.',
        'update_available' => 'An update is available.',
        'up_to_date' => 'The product is up to date.',
    ],
];
