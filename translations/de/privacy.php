<?php

declare(strict_types=1);

/**
 * Aufbewahrung und Löschung von Daten (SPECIFICATIONS.md §65).
 */

return [
    'retention' => 'Aufbewahrungsfristen',
    'retention_intro' => 'Daten zu schützen genügt nicht: sie dürfen auch nicht länger aufbewahrt werden, als es '
        . 'gerechtfertigt ist.',
    'kept_intro' => 'Aufenthalte, Zahlungen, angenommene Verträge und Übergabeprotokolle werden nie automatisch '
        . 'gelöscht: sie sind Vertragsunterlagen, und ihre Löschung bleibt eine menschliche Entscheidung.',
    'purge_now' => 'Jetzt anwenden',
    'purged' => 'Aufbewahrungsfristen angewendet.',
    'nothing_to_purge' => 'Nichts zu löschen.',
    'days' => '{days} Tage',
    'category' => [
        'logs' => 'Anwendungsprotokolle',
        'notifications' => 'Benachrichtigungsprotokoll',
        'guest_links' => 'Abgelaufene Gastlinks',
        'webhooks' => 'Zahlungsbenachrichtigungen',
        'police_records' => 'Meldescheine',
        'availability_blocks' => 'Vergangene Sperrzeiten',
    ],
];
