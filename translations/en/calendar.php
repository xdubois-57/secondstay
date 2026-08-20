<?php

declare(strict_types=1);

/**
 * Private calendars in iCalendar format.
 */

return [
    'title' => 'Private calendars',
    'intro' => 'These addresses are personal: they grant calendar access without a password. Do not share them.',
    'scope' => [
        'admin' => 'Administration',
        'manager' => 'Local manager',
        'customer' => 'My stay',
    ],
    'feed' => [
        'admin' => 'Stays — {property}',
        'manager' => 'Visits — {property}',
        'customer' => 'My stay — {property}',
    ],
    'event' => [
        'stay' => 'Stay {reference}',
    ],
    'action' => [
        'create' => 'Create a link',
        'revoke' => 'Revoke',
        'copy' => 'Calendar address',
        'subscribe' => 'Subscribe to the calendar',
    ],
    'created' => 'Calendar link created. Copy it now: it will not be shown again.',
    'revoked' => 'Calendar link revoked.',
    'once' => 'This address is shown only once.',
    'never_used' => 'Never used',
    'last_used' => 'Last used',
    'label' => 'Label',
    'created_at' => 'Created on',
    'empty' => 'No active calendar link.',
    'error' => [
        'not_found' => 'Calendar link not found.',
        'disabled' => 'Private calendars are disabled.',
    ],
];
