<?php

declare(strict_types=1);

/**
 * Periodische Aufgaben (ARCHITECTURE.md §23).
 */

return [
    'title' => 'Geplante Aufgaben',
    'intro' => 'Das Produkt betreibt keinen dauerhaften Prozess: Ein einziger Cron-Eintrag startet alles, was fällig '
        . 'ist. Richten Sie ihn bei Ihrem Hoster ein, so oft wie erlaubt.',
    'command_path' => '/pfad/zu/secondstay/src/Scheduler/cron.php',
    'never' => 'nie ausgeführt',
    'stale' => 'überfällig',
    'every' => 'höchstens alle {minutes} Minuten',
    'column' => [
        'task' => 'Aufgabe',
        'last_run' => 'Letzte Ausführung',
        'result' => 'Ergebnis',
    ],
    'action' => [
        'run' => 'Ausführen',
    ],
    'flash' => [
        'done' => 'Aufgabe ausgeführt.',
        'skipped' => 'Aufgabe nicht ausgeführt: Sie ist deaktiviert oder läuft bereits.',
        'failed' => 'Die Aufgabe ist fehlgeschlagen. Einzelheiten stehen im Protokoll.',
    ],
    'status' => [
        'never' => 'nie ausgeführt',
        'ok' => 'erfolgreich',
        'skipped' => 'übersprungen',
        'error' => 'fehlgeschlagen',
    ],
    'task' => [
        'booking_holds' => 'Abgelaufene Buchungssperren freigeben',
        'inbound_mail' => 'Das dedizierte Postfach abrufen',
        'calendar_import' => 'Externe Kalender synchronisieren',
        'local_content' => 'Lokale Inhalte erzeugen',
        'stay_reminders' => 'Erinnerungen, Anreisen und Abreisen',
        'retention' => 'Daten nach Ablauf der Aufbewahrung löschen',
        'backup' => 'Automatische Sicherung',
        'update_check' => 'Prüfung auf Aktualisierungen',
    ],
    'detail' => [
        'nothing' => 'Nichts zu tun.',
        'disabled' => 'Funktion in den Einstellungen deaktiviert.',
        'no_handler' => 'Dieser Aufgabe ist keine Verarbeitung zugeordnet.',
        'locked' => 'Eine Ausführung läuft bereits.',
        'exception' => 'Durch einen Fehler abgebrochen; siehe Protokoll.',
        'holds_released' => '{count} Sperre(n) freigegeben.',
        'mail_imported' => '{count} Nachricht(en) importiert.',
        'mailbox_unreachable' => 'Postfach nicht erreichbar.',
        'no_calendar' => 'Kein externer Kalender angegeben.',
        'calendar_failed' => '{count} Feed(s) haben nicht geantwortet.',
        'calendar_events' => '{count} Ereignis(se) importiert.',
        'local_content' => '{count} Aufenthalt(e) aktualisiert.',
        'local_content_failed' => '{count} Aufenthalt(e) konnten nicht erzeugt werden.',
        'reminders_sent' => '{count} Benachrichtigung(en) gesendet.',
        'purged' => '{count} Datensatz/Datensätze gelöscht.',
        'backup_done' => 'Sicherung erstellt; {count} alte entfernt.',
        'update_available' => 'Eine Aktualisierung ist verfügbar.',
        'up_to_date' => 'Das Produkt ist aktuell.',
    ],
];
