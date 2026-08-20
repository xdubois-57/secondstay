<?php

declare(strict_types=1);

/**
 * Incidents (SPECIFICATIONS.md §54).
 */

return [
    'title' => 'Incidents',
    'empty' => 'Aucun incident.',
    'description' => 'Description',
    'reported' => 'Incident signalé.',
    'updated' => 'Incident mis à jour.',
    'severity' => [
        'legend' => 'Urgence',
        'low' => 'Mineur',
        'normal' => 'Normal',
        'urgent' => 'Urgent',
    ],
    'status' => [
        'reported' => 'Signalé',
        'acknowledged' => 'Pris en charge',
        'resolved' => 'Résolu',
    ],
    'action' => [
        'acknowledged' => 'Prendre en charge',
        'resolved' => 'Marquer résolu',
    ],
    'event' => [
        'reported' => 'Signalé',
        'acknowledged' => 'Pris en charge',
        'resolved' => 'Résolu',
        'assigned' => 'Affecté',
        'comment' => 'Commentaire',
        'photo' => 'Photo ajoutée',
    ],
    'field' => [
        'title' => 'Objet',
        'severity' => 'Urgence',
        'status' => 'Statut',
        'booking' => 'Séjour',
        'no_booking' => 'Aucun séjour',
        'zone' => 'Zone',
        'no_zone' => 'Aucune zone',
        'created' => 'Signalé le',
        'resolved' => 'Résolu le',
        'note' => 'Note',
        'assignee' => 'Affecté à',
        'unassigned' => 'Personne',
        'photo' => 'Photo',
    ],
    'filter' => [
        'all' => 'Tous',
    ],
    'error' => [
        'title_required' => 'L’objet de l’incident est obligatoire.',
        'transition' => 'Ce changement de statut n’est pas possible.',
        'assignee' => 'Un incident ne peut être confié qu’à un rôle opérationnel.',
        'note_required' => 'La note est obligatoire.',
    ],
    'admin' => [
        'intro' => 'Suivi des incidents : signalé, pris en charge, résolu.',
        'new' => 'Nouvel incident',
        'actions' => 'Actions',
        'no_transition' => 'Aucune action possible depuis ce statut.',
        'history' => 'Historique',
    ],
];
