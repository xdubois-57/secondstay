<?php

declare(strict_types=1);

/**
 * Private Kalender im iCalendar-Format.
 */

return [
    'title' => 'Private Kalender',
    'intro' => 'Diese Adressen sind persönlich: Sie geben ohne Passwort Zugriff auf den Kalender. Geben Sie sie nicht '
        . 'weiter.',
    'scope' => [
        'admin' => 'Verwaltung',
        'manager' => 'Ansprechpartner vor Ort',
        'customer' => 'Mein Aufenthalt',
    ],
    'feed' => [
        'admin' => 'Aufenthalte — {property}',
        'manager' => 'Einsätze — {property}',
        'customer' => 'Mein Aufenthalt — {property}',
    ],
    'event' => [
        'stay' => 'Aufenthalt {reference}',
    ],
    'action' => [
        'create' => 'Link erstellen',
        'revoke' => 'Widerrufen',
        'copy' => 'Kalenderadresse',
        'subscribe' => 'Kalender abonnieren',
    ],
    'created' => 'Kalenderlink erstellt. Kopieren Sie ihn jetzt: er wird nicht erneut angezeigt.',
    'revoked' => 'Kalenderlink widerrufen.',
    'once' => 'Diese Adresse wird nur einmal angezeigt.',
    'never_used' => 'Nie verwendet',
    'last_used' => 'Zuletzt verwendet',
    'label' => 'Bezeichnung',
    'created_at' => 'Erstellt am',
    'empty' => 'Kein aktiver Kalenderlink.',
    'import' => [
        'title' => 'Importierte externe Kalender',
        'intro' => 'Auf einer anderen Plattform verkaufte Nächte werden zu Sperrzeiten. Ein importierter Feed erstellt '
            . 'nie eine Buchung und rührt manuell eingetragene Sperren nie an.',
        'url' => 'Adresse des Feeds',
        'label' => 'Bezeichnung',
        'provider_label' => 'Plattform',
        'last_sync' => 'Letzte Synchronisation',
        'events' => 'Gesperrte Nächte',
        'empty' => 'Kein externer Kalender importiert.',
        'never_synced' => 'Nie synchronisiert',
        'added' => 'Externer Kalender hinzugefügt. Starten Sie eine Synchronisation, um die Nächte zu importieren.',
        'deleted' => 'Externer Kalender gelöscht, samt der daraus entstandenen Sperren.',
        'synced' => 'Externe Kalender synchronisiert.',
        'partial' => 'Einige externe Kalender haben nicht geantwortet; bestehende Sperren bleiben erhalten.',
        'nothing' => 'Kein aktiver externer Kalender zum Synchronisieren.',
        'action' => [
            'add' => 'Hinzufügen',
            'sync' => 'Synchronisieren',
            'delete' => 'Löschen',
        ],
        'provider' => [
            'airbnb' => 'Airbnb',
            'booking' => 'Booking.com',
            'abritel' => 'Abritel',
            'other' => 'Andere',
        ],
        'status' => [
            'ok' => 'Aktuell',
            'blocked' => 'Adresse abgelehnt',
            'not_a_calendar' => 'Kein Kalender',
            'unavailable' => 'Nicht verfügbar',
        ],
        'error' => [
            'duplicate' => 'Dieser Feed ist bereits importiert.',
            'inactive' => 'Dieser Kalender ist deaktiviert.',
            'blocked' => 'Adresse abgelehnt: Der Feed kann nicht abgerufen werden.',
            'not_a_calendar' => 'Die Antwort ist kein iCalendar-Feed.',
            'unavailable' => 'Feed nicht verfügbar: Die Synchronisation ist fehlgeschlagen.',
        ],
    ],
    'error' => [
        'not_found' => 'Kalenderlink nicht gefunden.',
        'disabled' => 'Private Kalender sind deaktiviert.',
    ],
];
