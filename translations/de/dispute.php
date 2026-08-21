<?php

declare(strict_types=1);

/**
 * Streitfälle zu einem Aufenthalt (ROADMAP.md Iteration 14).
 */

return [
    'title' => 'Streitfälle',
    'intro' => 'Ein Streitfall bündelt, was das Produkt bereits erfasst hat — einbehaltene Kaution, Auszugsprotokoll, Vorfälle, angenommener Vertrag —, damit das Gespräch auf datierten Fakten beruht.',
    'empty' => 'Kein Streitfall.',
    'evidence' => 'Unterlagen in der Akte',
    'actions' => 'Nächster Schritt',
    'history' => 'Verlauf',
    'no_transition' => 'Aus diesem Status ist kein Statuswechsel möglich.',
    'opened' => 'Streitfall eröffnet.',
    'updated' => 'Streitfall aktualisiert.',
    'open_title' => 'Streitfall eröffnen',
    'filter' => [
        'all' => 'Alle',
    ],
    'field' => [
        'summary' => 'Gegenstand',
        'booking' => 'Aufenthalt',
        'claimed' => 'Geforderter Betrag',
        'settled' => 'Beglichener Betrag',
        'waived' => 'Erlassener Betrag',
        'status' => 'Status',
        'resolution' => 'Wie der Fall gelöst wurde',
        'note' => 'Beitrag hinzufügen',
        'kind' => 'Art',
    ],
    'kind' => [
        'deposit' => 'Kautionseinbehalt',
        'damage' => 'Beschädigung',
        'payment' => 'Zahlung',
        'other' => 'Andere',
    ],
    'status' => [
        'open' => 'Offen',
        'discussing' => 'In Klärung',
        'resolved' => 'Gelöst',
    ],
    'action' => [
        'discussing' => 'In Klärung setzen',
        'resolved' => 'Streitfall abschließen',
        'open' => 'Streitfall eröffnen',
    ],
    'event' => [
        'opened' => 'Streitfall eröffnet',
        'discussing' => 'In Klärung gesetzt',
        'resolved' => 'Streitfall gelöst',
        'comment' => 'Beitrag',
    ],
    'evidence_field' => [
        'deposit' => 'Einbehaltene Kaution',
        'checkout' => 'Auszugsprotokoll erstellt',
        'anomalies' => 'Beim Auszug festgestellte Abweichungen',
        'photos' => 'Fotos in der Akte',
        'incidents' => 'Erfasste Vorfälle',
        'contract' => 'Vertrag angenommen',
    ],
    'error' => [
        'summary_required' => 'Beschreiben Sie den Gegenstand des Streitfalls.',
        'above_deposit' => 'Der geforderte Einbehalt übersteigt die tatsächlich einbehaltene Kaution.',
        'amount' => 'Ungültiger Betrag.',
        'already_open' => 'Für diesen Aufenthalt besteht bereits ein Streitfall dieser Art.',
        'transition' => 'Dieser Statuswechsel ist nicht zulässig.',
        'resolution_required' => 'Erklären Sie, wie der Streitfall gelöst wurde.',
        'settlement' => 'Der beglichene Betrag muss zwischen null und dem geforderten Betrag liegen.',
        'note_required' => 'Geben Sie einen Beitrag ein, bevor Sie ihn hinzufügen.',
    ],
];
