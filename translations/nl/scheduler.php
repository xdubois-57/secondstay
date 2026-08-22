<?php

declare(strict_types=1);

/**
 * Periodieke taken (ARCHITECTURE.md §23).
 */

return [
    'title' => 'Geplande taken',
    'intro' => 'Het product draait geen permanent proces: één cron-regel start alles wat aan de beurt is. Voeg die toe bij uw hostingprovider, zo vaak als toegestaan.',
    'command_path' => '/pad/naar/secondstay/src/Scheduler/cron.php',
    'never' => 'nooit uitgevoerd',
    'stale' => 'achterstallig',
    'every' => 'hoogstens elke {minutes} minuten',
    'column' => [
        'task' => 'Taak',
        'last_run' => 'Laatste uitvoering',
        'result' => 'Resultaat',
    ],
    'action' => [
        'run' => 'Uitvoeren',
    ],
    'flash' => [
        'done' => 'Taak uitgevoerd.',
        'skipped' => 'Taak niet uitgevoerd: ze is uitgeschakeld of loopt al.',
        'failed' => 'De taak is mislukt. De details staan in het logboek.',
    ],
    'status' => [
        'never' => 'nooit uitgevoerd',
        'ok' => 'geslaagd',
        'skipped' => 'overgeslagen',
        'error' => 'mislukt',
    ],
    'task' => [
        'booking_holds' => 'Verlopen reserveringsvergrendelingen vrijgeven',
        'inbound_mail' => 'De speciale mailbox ophalen',
        'calendar_import' => 'Externe agenda’s synchroniseren',
        'local_content' => 'Lokale inhoud genereren',
        'stay_reminders' => 'Herinneringen, aankomsten en vertrekken',
        'retention' => 'Gegevens buiten de bewaartermijn wissen',
        'backup' => 'Automatische back-up',
        'update_check' => 'Controle op updates',
    ],
    'detail' => [
        'nothing' => 'Niets te doen.',
        'disabled' => 'Functie uitgeschakeld in de instellingen.',
        'no_handler' => 'Aan deze taak is geen verwerking gekoppeld.',
        'locked' => 'Er loopt al een uitvoering.',
        'exception' => 'Onderbroken door een fout; zie het logboek.',
        'holds_released' => '{count} vergrendeling(en) vrijgegeven.',
        'mail_imported' => '{count} bericht(en) geïmporteerd.',
        'mailbox_unreachable' => 'Mailbox onbereikbaar.',
        'no_calendar' => 'Geen externe agenda opgegeven.',
        'calendar_failed' => '{count} feed(s) hebben niet geantwoord.',
        'calendar_events' => '{count} gebeurtenis(sen) geïmporteerd.',
        'local_content' => '{count} verblijf(ven) vernieuwd.',
        'local_content_failed' => '{count} verblijf(ven) konden niet worden gegenereerd.',
        'reminders_sent' => '{count} melding(en) verstuurd.',
        'purged' => '{count} record(s) gewist.',
        'backup_done' => 'Back-up gemaakt; {count} oude verwijderd.',
        'update_available' => 'Er is een update beschikbaar.',
        'up_to_date' => 'Het product is up-to-date.',
    ],
];
