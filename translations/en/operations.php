<?php

declare(strict_types=1);

/**
 * Operations: local manager, checklists and the “To do” board.
 */

return [
    'title' => 'Operations',
    'todo' => [
        'title' => 'To do',
        'empty' => 'Nothing needs your attention.',
        'bookings_to_confirm' => 'Requests to approve',
        'payments_overdue' => 'Overdue instalments',
        'deposits_to_return' => 'Security deposits to return',
        'mail_unlinked' => 'Unattached messages',
        'stays_to_prepare' => 'Stays to prepare',
        'incidents_open' => 'Open incidents',
        'compliance_to_verify' => 'Compliance to verify',
        'disputes_open' => 'Open disputes',
        'migrations_pending' => 'Pending migrations',
    ],
    'phase' => [
        'before' => 'Before the stay',
        'departure' => 'On departure',
    ],
    'item' => [
        'contract' => 'Agreement accepted',
        'deposit' => 'Deposit collected',
        'balance' => 'Balance collected',
        'security_deposit' => 'Security deposit received',
        'manager' => 'Manager assigned',
        'cleaning_scheduled' => 'Cleaning scheduled',
        'access_shared' => 'Access details sent',
        'welcome_sent' => 'Welcome message sent',
        'inventory_done' => 'Inventory completed',
        'incidents_reviewed' => 'Incidents reviewed',
        'cleaning_done' => 'Cleaning done',
        'deposit_settled' => 'Security deposit settled',
    ],
    'manager' => [
        'title' => 'Local manager',
        'contact' => 'Local manager',
        'assign' => 'Assign',
        'assigned' => 'Manager assigned.',
        'unassigned' => 'No manager',
        'default' => 'Default manager',
        'none' => '— none —',
        'my_stays' => 'My stays',
        'empty' => 'No stay is assigned to you.',
    ],
    'checklist' => [
        'title' => 'Checklist',
        'progress' => '{done} of {total}',
        'derived' => 'Tracked automatically',
        'save' => 'Save',
        'updated' => 'Checklist updated.',
        'note' => 'Note',
    ],
    'prepare' => [
        'title' => 'Stays to prepare',
        'empty' => 'No upcoming stay is waiting on preparation.',
        'arrival' => 'Arrival',
        'remaining' => 'Still to do',
    ],
    'error' => [
        'unknown_item' => 'Unknown checklist item.',
        'manager_invalid' => 'That account is not a local manager.',
        'booking_not_found' => 'Stay not found.',
    ],
];
