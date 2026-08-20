<?php

declare(strict_types=1);

/**
 * Private Kalender im iCalendar-Format.
 */

return [
    'title' => 'Private Kalender',
    'intro' => 'Diese Adressen sind persönlich: Sie geben ohne Passwort Zugriff auf den Kalender. Geben Sie sie nicht weiter.',
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
    'error' => [
        'not_found' => 'Kalenderlink nicht gefunden.',
        'disabled' => 'Private Kalender sind deaktiviert.',
    ],
];
