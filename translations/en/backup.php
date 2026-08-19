<?php

declare(strict_types=1);

return [
    'error' => [
        'unreadable' => 'Backup archive unreadable.',
        'no_manifest' => 'Manifest missing from the backup.',
        'invalid_manifest' => 'Invalid backup manifest.',
        'format_too_recent' => 'Backup format newer than this version.',
        'no_sql' => 'SQL dump missing from the backup.',
        'missing_entry' => 'A declared entry is missing from the archive.',
        'checksum' => 'Checksum mismatch: the backup is corrupted.',
        'restore_failed' => 'The test restore failed.',
    ],
];
