<?php

declare(strict_types=1);

/**
 * Storage quotas (ROADMAP.md iteration 14).
 */

return [
    'title' => 'Storage space',
    'mb' => 'MB',
    'usage' => 'Space used for this category',
    'no_limit' => 'no limit',
    'configure' => 'Set the quotas',
    'category' => [
        'media' => 'Media',
        'documents' => 'Documents',
        'backups' => 'Backups',
        'mail-attachments' => 'Attachments',
    ],
    'error' => [
        'media' => 'The media quota is reached. Free some space or raise the limit before adding a file.',
        'documents' => 'The document quota is reached. Free some space or raise the limit before adding a file.',
        'backups' => 'The backup quota is reached. Delete old backups or raise the limit.',
        'mail-attachments' => 'The attachment quota is reached.',
    ],
];
