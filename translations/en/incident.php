<?php

declare(strict_types=1);

/**
 * Incidents (SPECIFICATIONS.md §54).
 */

return [
    'title' => 'Incidents',
    'empty' => 'No incident.',
    'description' => 'Description',
    'reported' => 'Incident reported.',
    'updated' => 'Incident updated.',
    'severity' => [
        'legend' => 'Urgency',
        'low' => 'Minor',
        'normal' => 'Normal',
        'urgent' => 'Urgent',
    ],
    'status' => [
        'reported' => 'Reported',
        'acknowledged' => 'Acknowledged',
        'resolved' => 'Resolved',
    ],
    'action' => [
        'acknowledged' => 'Take it on',
        'resolved' => 'Mark as resolved',
    ],
    'event' => [
        'reported' => 'Reported',
        'acknowledged' => 'Acknowledged',
        'resolved' => 'Resolved',
        'assigned' => 'Assigned',
        'comment' => 'Comment',
        'photo' => 'Photo added',
    ],
    'field' => [
        'title' => 'Subject',
        'severity' => 'Urgency',
        'status' => 'Status',
        'booking' => 'Stay',
        'no_booking' => 'No stay',
        'zone' => 'Zone',
        'no_zone' => 'No zone',
        'created' => 'Reported on',
        'resolved' => 'Resolved on',
        'note' => 'Note',
        'assignee' => 'Assigned to',
        'unassigned' => 'Nobody',
        'photo' => 'Photo',
    ],
    'filter' => [
        'all' => 'All',
    ],
    'error' => [
        'title_required' => 'The subject of the incident is required.',
        'transition' => 'This status change is not possible.',
        'assignee' => 'An incident can only be assigned to an operational role.',
        'note_required' => 'The note is required.',
    ],
    'admin' => [
        'intro' => 'Incident tracking: reported, acknowledged, resolved.',
        'new' => 'New incident',
        'actions' => 'Actions',
        'no_transition' => 'No action is possible from this status.',
        'history' => 'History',
    ],
];
