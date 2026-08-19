<?php

declare(strict_types=1);

/**
 * Libellés et aides des réglages typés.
 *
 * Chaque réglage expose un libellé et une aide explicite : l'objectif est que
 * le propriétaire comprenne l'impact du réglage sans documentation externe.
 */

return [
    'property' => [
        'name' => [
            'label' => 'Property name',
            'help' => 'Name shown publicly for your holiday home.',
        ],
        'address_line1' => [
            'label' => 'Address (line 1)',
            'help' => 'Street number and name of the property.',
        ],
        'address_line2' => [
            'label' => 'Address (line 2)',
            'help' => 'Additional address information: locality, building, floor.',
        ],
        'postal_code' => [
            'label' => 'Postal code',
            'help' => 'French postal code of the property.',
        ],
        'city' => [
            'label' => 'Town',
            'help' => 'Town of the property. Also used for the tourist tax.',
        ],
        'country' => [
            'label' => 'Country',
            'help' => 'SecondStay is specialised for France.',
        ],
        'latitude' => [
            'label' => 'Latitude',
            'help' => 'Coordinate used for local content and directions.',
        ],
        'longitude' => [
            'label' => 'Longitude',
            'help' => 'Coordinate used for local content and directions.',
        ],
        'contact_email' => [
            'label' => 'Contact email',
            'help' => 'Address shown to guests for general questions.',
        ],
        'contact_phone' => [
            'label' => 'Contact phone',
            'help' => 'Number shown to guests.',
        ],
    ],
    'site' => [
        'default_locale' => [
            'label' => 'Default language',
            'help' => 'Language used when no preference is known.',
        ],
        'timezone' => [
            'label' => 'Time zone',
            'help' => 'Zone used for check-in, check-out times and reminders.',
        ],
        'public_url' => [
            'label' => 'Public URL',
            'help' => 'Public address of the site, used in emails and links.',
        ],
        'season' => [
            'label' => 'Displayed season',
            'help' => 'Automatic follows the date; force summer or winter for a fixed presentation.',
        ],
    ],
    'booking' => [
        'min_nights' => [
            'label' => 'Minimum nights',
            'help' => 'Minimum accepted length of stay.',
        ],
        'max_guests' => [
            'label' => 'Maximum capacity',
            'help' => 'Total number of guests accepted, infants included per your rule.',
        ],
        'checkin_time' => [
            'label' => 'Check-in time',
            'help' => 'Time from which the property is available.',
        ],
        'checkout_time' => [
            'label' => 'Check-out time',
            'help' => 'Deadline for vacating the property.',
        ],
        'saturday_to_saturday' => [
            'label' => 'Saturday-to-Saturday rule',
            'help' => 'Forces Saturday to Saturday stays. Can be disabled.',
        ],
        'hold_minutes' => [
            'label' => 'Temporary hold duration',
            'help' => 'Minutes during which dates stay blocked before confirmation.',
        ],
    ],
    'pricing' => [
        'default_night_price' => [
            'label' => 'Default nightly price',
            'help' => 'Used for any date without a specific rate.',
        ],
        'cleaning_mode' => [
            'label' => 'Cleaning mode',
            'help' => 'None, optional or mandatory. Mandatory by default.',
        ],
        'cleaning_price' => [
            'label' => 'Cleaning price',
            'help' => 'Cleaning fee amount. Default value: €100.',
        ],
        'deposit_percent' => [
            'label' => 'Deposit (%)',
            'help' => 'Share of the stay required to confirm the booking.',
        ],
        'security_deposit' => [
            'label' => 'Security deposit',
            'help' => 'Deposit amount requested before the stay.',
        ],
    ],
    'maintenance' => [
        'enabled' => [
            'label' => 'Planned maintenance',
            'help' => 'Closes the public site. Administration stays reachable.',
        ],
        'message' => [
            'label' => 'Maintenance message',
            'help' => 'Internal note explaining the reason for maintenance.',
        ],
    ],
    'backup' => [
        'retention_count' => [
            'label' => 'Backups kept',
            'help' => 'Number of backups kept before automatic deletion.',
        ],
        'include_media' => [
            'label' => 'Include media',
            'help' => 'Adds photos, documents and attachments to the backup.',
        ],
    ],
    'update' => [
        'channel' => [
            'label' => 'Update channel',
            'help' => 'Stable installs published releases only.',
        ],
        'auto_install' => [
            'label' => 'Automatic update',
            'help' => 'Automatically installs new validated versions.',
        ],
        'repository' => [
            'label' => 'Release repository',
            'help' => 'GitHub repository providing installable artifacts.',
        ],
    ],
    'logging' => [
        'level' => [
            'label' => 'Logging level',
            'help' => 'Minimum severity of recorded messages.',
        ],
        'retention_days' => [
            'label' => 'Log retention (days)',
            'help' => 'Retention period before automatic purge.',
        ],
    ],
    'error' => [
        'required' => 'This setting is required.',
        'unknown' => 'Unknown setting.',
        'integer' => 'Enter a whole number.',
        'decimal' => 'Enter a decimal number.',
        'money' => 'Enter a valid amount, for example 100.00.',
        'enum' => 'Value not allowed.',
        'email' => 'Invalid email address.',
        'url' => 'Invalid URL.',
        'url_scheme' => 'Only http(s) URLs are accepted.',
        'date' => 'Invalid date (expected format: YYYY-MM-DD).',
        'time' => 'Invalid time (expected format: HH:MM).',
        'duration' => 'Invalid duration (in minutes).',
        'json' => 'Invalid JSON.',
        'too_long' => 'Value is too long.',
        'too_small' => 'Value is too small.',
        'too_large' => 'Value is too large.',
    ],
];
