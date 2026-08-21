<?php

declare(strict_types=1);

/**
 * Bewaring en opschoning van gegevens (SPECIFICATIONS.md §65).
 */

return [
    'retention' => 'Bewaartermijnen',
    'retention_intro' => 'Gegevens beschermen volstaat niet: ze mogen ook niet langer worden bewaard dan gerechtvaardigd is.',
    'kept_intro' => 'Verblijven, betalingen, aanvaarde contracten en plaatsbeschrijvingen worden nooit automatisch gewist: het zijn contractuele bewijsstukken, en het verwijderen ervan blijft een menselijke beslissing.',
    'purge_now' => 'Nu toepassen',
    'purged' => 'Bewaartermijnen toegepast.',
    'nothing_to_purge' => 'Niets op te schonen.',
    'days' => '{days} dagen',
    'category' => [
        'logs' => 'Applicatielogboeken',
        'notifications' => 'Meldingenlogboek',
        'guest_links' => 'Verlopen gastlinks',
        'webhooks' => 'Betalingsmeldingen',
        'police_records' => 'Politiefiches',
    ],
];
