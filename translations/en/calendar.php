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
    'import' => [
        'title' => 'Imported external calendars',
        'intro' => 'Nights sold on another platform become unavailable dates. An imported feed never creates a booking '
            . 'and never touches manually entered blocks.',
        'url' => 'Feed address',
        'label' => 'Label',
        'provider_label' => 'Platform',
        'last_sync' => 'Last synchronisation',
        'events' => 'Blocked nights',
        'empty' => 'No external calendar imported.',
        'never_synced' => 'Never synchronised',
        'added' => 'External calendar added. Run a synchronisation to import the nights.',
        'deleted' => 'External calendar removed, along with the blocks it created.',
        'synced' => 'External calendars synchronised.',
        'partial' => 'Some external calendars did not answer; existing blocks are kept.',
        'nothing' => 'No active external calendar to synchronise.',
        'action' => [
            'add' => 'Add',
            'sync' => 'Synchronise',
            'delete' => 'Delete',
        ],
        'provider' => [
            'airbnb' => 'Airbnb',
            'booking' => 'Booking.com',
            'abritel' => 'Abritel',
            'other' => 'Other',
        ],
        'status' => [
            'ok' => 'Up to date',
            'blocked' => 'Address refused',
            'not_a_calendar' => 'Not a calendar',
            'unavailable' => 'Unavailable',
        ],
        'error' => [
            'duplicate' => 'This feed is already imported.',
            'inactive' => 'This calendar is disabled.',
            'blocked' => 'Address refused: the feed cannot be fetched.',
            'not_a_calendar' => 'The response is not an iCalendar feed.',
            'unavailable' => 'Feed unavailable: the synchronisation failed.',
        ],
    ],
    'error' => [
        'not_found' => 'Calendar link not found.',
        'disabled' => 'Private calendars are disabled.',
    ],
];
