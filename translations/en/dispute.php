<?php

declare(strict_types=1);

/**
 * Disputes attached to a stay (ROADMAP.md iteration 14).
 */

return [
    'title' => 'Disputes',
    'intro' => 'A dispute gathers what the product already collected — deposit held, check-out inspection, incidents, '
        . 'accepted contract — so the discussion rests on dated facts.',
    'empty' => 'No dispute.',
    'evidence' => 'Evidence on file',
    'actions' => 'Next step',
    'history' => 'History',
    'no_transition' => 'No state change is possible from this state.',
    'opened' => 'Dispute opened.',
    'updated' => 'Dispute updated.',
    'open_title' => 'Open a dispute',
    'filter' => [
        'all' => 'All',
    ],
    'field' => [
        'summary' => 'Subject',
        'booking' => 'Stay',
        'claimed' => 'Amount claimed',
        'settled' => 'Amount settled',
        'waived' => 'Amount waived',
        'status' => 'State',
        'resolution' => 'How it was resolved',
        'note' => 'Add an exchange',
        'kind' => 'Nature',
    ],
    'kind' => [
        'deposit' => 'Deposit retention',
        'damage' => 'Damage',
        'payment' => 'Payment',
        'other' => 'Other',
    ],
    'status' => [
        'open' => 'Open',
        'discussing' => 'Under discussion',
        'resolved' => 'Resolved',
    ],
    'action' => [
        'discussing' => 'Move to discussion',
        'resolved' => 'Close the dispute',
        'open' => 'Open the dispute',
    ],
    'event' => [
        'opened' => 'Dispute opened',
        'discussing' => 'Moved to discussion',
        'resolved' => 'Dispute resolved',
        'comment' => 'Exchange',
    ],
    'evidence_field' => [
        'deposit' => 'Deposit held',
        'checkout' => 'Check-out inspection completed',
        'anomalies' => 'Anomalies found at check-out',
        'photos' => 'Photos on file',
        'incidents' => 'Recorded incidents',
        'contract' => 'Contract accepted',
    ],
    'error' => [
        'summary_required' => 'Describe the subject of the dispute.',
        'above_deposit' => 'The claimed retention exceeds the deposit actually held.',
        'amount' => 'Invalid amount.',
        'already_open' => 'A dispute of this nature already exists on this stay.',
        'transition' => 'This state change is not allowed.',
        'resolution_required' => 'Explain how the dispute was resolved.',
        'settlement' => 'The settled amount must be between zero and the claimed amount.',
        'note_required' => 'Type an exchange before adding it.',
    ],
];
