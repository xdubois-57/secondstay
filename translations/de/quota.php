<?php

declare(strict_types=1);

/**
 * Speicherkontingente (ROADMAP.md Iteration 14).
 */

return [
    'title' => 'Speicherplatz',
    'mb' => 'MB',
    'no_limit' => 'keine Grenze',
    'configure' => 'Kontingente einstellen',
    'category' => [
        'media' => 'Medien',
        'documents' => 'Dokumente',
        'backups' => 'Sicherungen',
        'mail-attachments' => 'Anhänge',
    ],
    'error' => [
        'media' => 'Das Medienkontingent ist erreicht. Geben Sie Platz frei oder erhöhen Sie die Grenze, bevor Sie eine Datei hinzufügen.',
        'documents' => 'Das Dokumentenkontingent ist erreicht. Geben Sie Platz frei oder erhöhen Sie die Grenze, bevor Sie eine Datei hinzufügen.',
        'backups' => 'Das Sicherungskontingent ist erreicht. Löschen Sie alte Sicherungen oder erhöhen Sie die Grenze.',
        'mail-attachments' => 'Das Anhangkontingent ist erreicht.',
    ],
];
