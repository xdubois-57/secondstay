<?php

declare(strict_types=1);

return [
    'error' => [
        'unreadable' => 'Sicherungsarchiv nicht lesbar.',
        'no_manifest' => 'Manifest fehlt in der Sicherung.',
        'invalid_manifest' => 'Ungültiges Sicherungsmanifest.',
        'format_too_recent' => 'Sicherungsformat neuer als diese Version.',
        'no_sql' => 'SQL-Dump fehlt in der Sicherung.',
        'missing_entry' => 'Ein angekündigter Eintrag fehlt im Archiv.',
        'checksum' => 'Prüfsumme stimmt nicht: die Sicherung ist beschädigt.',
        'restore_failed' => 'Die Testwiederherstellung ist fehlgeschlagen.',
    ],
];
