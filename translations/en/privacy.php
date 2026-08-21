<?php

declare(strict_types=1);

/**
 * Data retention and purge (SPECIFICATIONS.md §65).
 */

return [
    'retention' => 'Retention periods',
    'retention_intro' => 'Protecting data is not enough: it must also not be kept beyond what justifies it.',
    'kept_intro' => 'Stays, payments, accepted contracts and inspections are never purged automatically: they are contractual evidence, and deleting them stays a human decision.',
    'purge_now' => 'Apply now',
    'purged' => 'Retention applied.',
    'nothing_to_purge' => 'Nothing to purge.',
    'days' => '{days} days',
    'category' => [
        'logs' => 'Application logs',
        'notifications' => 'Notification journal',
        'guest_links' => 'Expired guest links',
        'webhooks' => 'Payment notifications',
        'police_records' => 'Police records',
        'availability_blocks' => 'Past unavailable dates',
    ],
];
