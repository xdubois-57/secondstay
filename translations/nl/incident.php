<?php

declare(strict_types=1);

/**
 * Incidenten (SPECIFICATIONS.md §54).
 */

return [
    'title' => 'Incidenten',
    'empty' => 'Geen incident.',
    'description' => 'Beschrijving',
    'reported' => 'Incident gemeld.',
    'updated' => 'Incident bijgewerkt.',
    'severity' => [
        'legend' => 'Dringendheid',
        'low' => 'Klein',
        'normal' => 'Normaal',
        'urgent' => 'Dringend',
    ],
    'status' => [
        'reported' => 'Gemeld',
        'acknowledged' => 'In behandeling',
        'resolved' => 'Opgelost',
    ],
    'action' => [
        'acknowledged' => 'In behandeling nemen',
        'resolved' => 'Als opgelost markeren',
    ],
    'event' => [
        'reported' => 'Gemeld',
        'acknowledged' => 'In behandeling',
        'resolved' => 'Opgelost',
        'assigned' => 'Toegewezen',
        'comment' => 'Opmerking',
        'photo' => 'Foto toegevoegd',
    ],
    'field' => [
        'title' => 'Onderwerp',
        'severity' => 'Dringendheid',
        'status' => 'Status',
        'booking' => 'Verblijf',
        'no_booking' => 'Geen verblijf',
        'zone' => 'Zone',
        'no_zone' => 'Geen zone',
        'created' => 'Gemeld op',
        'resolved' => 'Opgelost op',
        'note' => 'Nota',
        'assignee' => 'Toegewezen aan',
        'unassigned' => 'Niemand',
        'photo' => 'Foto',
    ],
    'filter' => [
        'all' => 'Alle',
    ],
    'error' => [
        'title_required' => 'Het onderwerp van het incident is verplicht.',
        'transition' => 'Deze statuswijziging is niet mogelijk.',
        'assignee' => 'Een incident kan alleen aan een operationele rol worden toegewezen.',
        'note_required' => 'De nota is verplicht.',
    ],
    'admin' => [
        'intro' => 'Opvolging van incidenten: gemeld, in behandeling, opgelost.',
        'new' => 'Nieuw incident',
        'actions' => 'Acties',
        'no_transition' => 'Vanuit deze status is geen actie mogelijk.',
        'history' => 'Geschiedenis',
    ],
];
