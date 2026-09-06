<?php

declare(strict_types=1);

/**
 * Individueller Meldeschein (SPECIFICATIONS.md §64).
 */

return [
    'title' => 'Meldescheine',
    'record' => 'Meldeschein',
    'open_record' => 'Meldeschein öffnen',
    'intro' => 'Der individuelle Meldeschein ist nur in bestimmten Fällen erforderlich. Solange er nicht aktiviert '
        . 'ist, werden keine Identitätsdaten erhoben.',
    'record_intro' => 'Die Daten sind verschlüsselt und werden nach Ablauf der Aufbewahrungsfrist automatisch '
        . 'gelöscht.',
    'enabled' => 'Der Meldeschein wird für die betroffenen Aufenthalte verlangt.',
    'disabled' => 'Der Meldeschein ist deaktiviert: es wird nichts erhoben.',
    'configure' => 'Konfigurieren',
    'records' => 'Gespeicherte Meldescheine',
    'empty' => 'Kein Meldeschein gespeichert.',
    'unreadable' => 'Unlesbarer Meldeschein',
    'saved' => 'Meldeschein gespeichert.',
    'deleted' => 'Meldeschein gelöscht.',
    'purge_after' => 'Löschung am {date}',
    'retention' => 'Aufbewahrung: {days} Tage nach der Abreise.',
    'field' => [
        'last_name' => 'Name',
        'first_names' => 'Vornamen',
        'birth_date' => 'Geburtsdatum',
        'birth_place' => 'Geburtsort',
        'nationality' => 'Staatsangehörigkeit',
        'home_address' => 'Gewöhnlicher Wohnsitz',
        'arrival_date' => 'Anreisedatum',
        'departure_date' => 'Voraussichtliches Abreisedatum',
    ],
    'error' => [
        'disabled' => 'Der Meldeschein ist nicht aktiviert.',
        'incomplete' => 'Name, Vornamen, Geburtsdatum und Staatsangehörigkeit sind erforderlich.',
    ],
];
