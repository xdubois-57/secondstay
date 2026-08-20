<?php

declare(strict_types=1);

/**
 * Vorfälle (SPECIFICATIONS.md §54).
 */

return [
    'title' => 'Vorfälle',
    'empty' => 'Kein Vorfall.',
    'description' => 'Beschreibung',
    'reported' => 'Vorfall gemeldet.',
    'updated' => 'Vorfall aktualisiert.',
    'severity' => [
        'legend' => 'Dringlichkeit',
        'low' => 'Gering',
        'normal' => 'Normal',
        'urgent' => 'Dringend',
    ],
    'status' => [
        'reported' => 'Gemeldet',
        'acknowledged' => 'In Bearbeitung',
        'resolved' => 'Gelöst',
    ],
    'action' => [
        'acknowledged' => 'In Bearbeitung nehmen',
        'resolved' => 'Als gelöst markieren',
    ],
    'event' => [
        'reported' => 'Gemeldet',
        'acknowledged' => 'In Bearbeitung',
        'resolved' => 'Gelöst',
        'assigned' => 'Zugewiesen',
        'comment' => 'Anmerkung',
        'photo' => 'Foto hinzugefügt',
    ],
    'field' => [
        'title' => 'Betreff',
        'severity' => 'Dringlichkeit',
        'status' => 'Status',
        'booking' => 'Aufenthalt',
        'no_booking' => 'Kein Aufenthalt',
        'zone' => 'Bereich',
        'no_zone' => 'Kein Bereich',
        'created' => 'Gemeldet am',
        'resolved' => 'Gelöst am',
        'note' => 'Notiz',
        'assignee' => 'Zugewiesen an',
        'unassigned' => 'Niemand',
        'photo' => 'Foto',
    ],
    'filter' => [
        'all' => 'Alle',
    ],
    'error' => [
        'title_required' => 'Der Betreff des Vorfalls ist erforderlich.',
        'transition' => 'Diese Statusänderung ist nicht möglich.',
        'assignee' => 'Ein Vorfall kann nur einer operativen Rolle zugewiesen werden.',
        'note_required' => 'Die Notiz ist erforderlich.',
    ],
    'admin' => [
        'intro' => 'Nachverfolgung der Vorfälle: gemeldet, in Bearbeitung, gelöst.',
        'new' => 'Neuer Vorfall',
        'actions' => 'Aktionen',
        'no_transition' => 'Aus diesem Status ist keine Aktion möglich.',
        'history' => 'Verlauf',
    ],
];
