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
        'siret' => [
            'label' => 'SIRET',
            'help' => 'Registration number, printed on the contract when filled in.',
        ],
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
        'requires_approval' => [
            'label' => 'Owner approval',
            'help' => 'Every booking request waits for your agreement. Turn this off to confirm available stays automatically.',
        ],
        'allow_waitlist' => [
            'label' => 'Waiting list',
            'help' => 'A visitor can ask to be told if unavailable dates are freed.',
        ],
        'min_adults' => [
            'label' => 'Minimum adults',
            'help' => 'Minimum number of adults per stay.',
        ],
        'max_children' => [
            'label' => 'Maximum children',
            'help' => 'Maximum number of children accepted on top of the adults.',
        ],
        'max_infants' => [
            'label' => 'Maximum infants',
            'help' => 'Infants do not count towards the sleeping capacity.',
        ],
        'night_multiple' => [
            'label' => 'Night blocks',
            'help' => 'Forces a length that is a multiple of this number. 0 disables the rule, 7 forces whole weeks.',
        ],
        'max_nights' => [
            'label' => 'Maximum length',
            'help' => 'Maximum number of nights per stay.',
        ],
        'arrival_weekday' => [
            'label' => 'Fixed arrival day',
            'help' => 'Restricts arrivals to a single weekday. The Saturday-to-Saturday setting takes precedence.',
        ],
        'advance_days' => [
            'label' => 'Notice period',
            'help' => 'Minimum number of days between today and an arrival.',
        ],
        'horizon_days' => [
            'label' => 'Booking horizon',
            'help' => 'Number of days beyond which the calendar is not open yet.',
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
        'iban' => 'Invalid IBAN: check the check digits.',
        'bic' => 'Invalid BIC (8 or 11 characters).',
        'currency' => 'Invalid currency: a three-letter ISO 4217 code is expected.',
        'too_long' => 'Value is too long.',
        'too_small' => 'Value is too small.',
        'too_large' => 'Value is too large.',
    ],
    'mail' => [
        'from_address' => [
            'label' => 'Sender address',
            'help' => 'Address shown as the sender of the site\'s emails.',
        ],
        'from_name' => [
            'label' => 'Sender name',
            'help' => 'Name shown next to the sender address.',
        ],
        'reply_to' => [
            'label' => 'Reply-to address',
            'help' => 'Monitored mailbox that receives guest replies.',
        ],
        'smtp_host' => [
            'label' => 'SMTP server',
            'help' => 'Host provided by your email sending service.',
        ],
        'smtp_port' => [
            'label' => 'SMTP port',
            'help' => '587 with STARTTLS, 465 with implicit TLS.',
        ],
        'smtp_encryption' => [
            'label' => 'SMTP encryption',
            'help' => 'STARTTLS is recommended. The server certificate is always verified.',
        ],
        'smtp_username' => [
            'label' => 'SMTP user',
            'help' => 'Authentication identifier for the SMTP server.',
        ],
        'smtp_password' => [
            'label' => 'SMTP password',
            'help' => 'Encrypted at rest and never displayed again. Leave empty to keep the current value.',
        ],
        'dkim_selector' => [
            'label' => 'DKIM selector',
            'help' => 'Selector provided by your sending service (often “default” or “mail”). It is only used for the DNS diagnostic: signing remains the provider’s responsibility.',
        ],
    ],
    'notification' => [
        'push_enabled' => [
            'label' => 'Push notifications',
            'help' => 'Lets browsers receive notifications. Email is still sent in every case.',
        ],
        'retention_days' => [
            'label' => 'Notification log retention',
            'help' => 'How long delivery records are kept, in days.',
        ],
    ],
    'push' => [
        'subject' => [
            'label' => 'Push contact',
            'help' => 'Contact email address or URL sent to push services, as the standard requires. The sender address is used when left empty.',
        ],
        'vapid_public' => [
            'label' => 'VAPID public key',
            'help' => 'Generated by the installation and handed to browsers. Replacing it invalidates every existing subscription.',
        ],
        'vapid_private' => [
            'label' => 'VAPID private key',
            'help' => 'Encrypted at rest and never displayed again. It signs deliveries to push services.',
        ],
    ],
    'account' => [
        'allow_signup' => [
            'label' => 'Allow sign-ups',
            'help' => 'Lets guests create an account from the public site.',
        ],
        'allow_passkeys' => [
            'label' => 'Allow passkeys',
            'help' => 'Enables password-free sign-in with passkeys (WebAuthn).',
        ],
        'require_email_confirmation' => [
            'label' => 'Require email confirmation',
            'help' => 'The account stays inactive until the address is confirmed.',
        ],
    ],
    'payment' => [
        'provider' => [
            'label' => 'Payment provider',
            'help' => 'Mollie collects online and confirms the booking automatically. Without a provider, only bank transfer remains available.',
        ],
        'mollie_api_key' => [
            'label' => 'Mollie API key',
            'help' => 'Encrypted at rest and never shown again. A “test_” key never collects real money.',
        ],
        'balance_days_before' => [
            'label' => 'Balance due (days before arrival)',
            'help' => 'The balance falls due this many days before arrival, or immediately for a later booking.',
        ],
        'transfer_enabled' => [
            'label' => 'Allow bank transfer',
            'help' => 'Shows the IBAN and the EPC QR code. A transfer never confirms a booking on its own.',
        ],
        'beneficiary_name' => [
            'label' => 'Transfer beneficiary',
            'help' => 'Account holder name, as it will appear in the traveller’s banking app.',
        ],
        'iban' => [
            'label' => 'IBAN',
            'help' => 'IBAN of the account to credit. The check digits are verified before saving.',
        ],
        'bic' => [
            'label' => 'BIC',
            'help' => 'Optional. Some banks still ask for it on transfers outside the SEPA area.',
        ],
        'currency' => [
            'label' => 'Currency',
            'help' => 'Three-letter ISO 4217 code, EUR by default.',
        ],
    ],
    'tax' => [
        'tourist_enabled' => [
            'label' => 'Collect tourist tax',
            'help' => 'Adds the tourist tax to every booking schedule.',
        ],
        'tourist_per_adult_night' => [
            'label' => 'Tax per adult per night',
            'help' => 'Amount collected for each adult and each night. Minors are exempt.',
        ],
        'tourist_cap_per_stay' => [
            'label' => 'Cap per stay',
            'help' => 'Maximum amount collected for one stay. Zero means no cap.',
        ],
    ],
    'imap' => [
        'enabled' => [
            'label' => 'Poll the mailbox',
            'help' => 'Periodically imports travellers’ replies and their attachments.',
        ],
        'host' => [
            'label' => 'IMAP server',
            'help' => 'Host name of the mailbox dedicated to the property.',
        ],
        'port' => [
            'label' => 'IMAP port',
            'help' => '993 for an encrypted connection, 143 with STARTTLS.',
        ],
        'encryption' => [
            'label' => 'IMAP encryption',
            'help' => 'Implicit TLS is recommended. No encryption should be avoided.',
        ],
        'username' => [
            'label' => 'IMAP username',
            'help' => 'Account of the polled mailbox.',
        ],
        'password' => [
            'label' => 'IMAP password',
            'help' => 'Encrypted at rest and never shown again.',
        ],
        'mailbox' => [
            'label' => 'Polled folder',
            'help' => 'INBOX unless replies land in a dedicated folder.',
        ],
        'reply_address' => [
            'label' => 'Reply address',
            'help' => 'Address announced to travellers. It is tagged per booking so replies attach themselves.',
        ],
        'uid_validity' => [
            'label' => 'Validity identifier',
            'help' => 'Filled in by the sync. If it changes, the mailbox was renumbered and polling restarts from the beginning.',
        ],
        'batch_size' => [
            'label' => 'Messages per poll',
            'help' => 'How many messages each pass handles. A poll must always finish.',
        ],
    ],
    'legal' => [
        'terms_version' => [
            'label' => 'Terms version',
            'help' => 'Frozen into every accepted contract. Change it when the terms text changes.',
        ],
        'mediator_name' => [
            'label' => 'Consumer mediator',
            'help' => 'Name of the mediator quoted in the contract.',
        ],
        'mediator_url' => [
            'label' => 'Mediator website',
            'help' => 'Where the traveller can refer a dispute to the mediator.',
        ],
    ],
];
