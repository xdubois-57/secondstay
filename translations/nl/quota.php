<?php

declare(strict_types=1);

/**
 * Opslagquota (SPECIFICATIONS.md §67).
 */

return [
    'title' => 'Opslagruimte',
    'mb' => 'MB',
    'no_limit' => 'geen limiet',
    'configure' => 'Quota instellen',
    'category' => [
        'media' => 'Media',
        'documents' => 'Documenten',
        'backups' => 'Back-ups',
        'mail-attachments' => 'Bijlagen',
    ],
    'error' => [
        'media' => 'Het mediaquotum is bereikt. Maak ruimte vrij of verhoog de limiet voordat u een bestand toevoegt.',
        'documents' => 'Het documentquotum is bereikt. Maak ruimte vrij of verhoog de limiet voordat u een bestand toevoegt.',
        'backups' => 'Het back-upquotum is bereikt. Verwijder oude back-ups of verhoog de limiet.',
        'mail-attachments' => 'Het bijlagequotum is bereikt.',
    ],
];
