<?php

declare(strict_types=1);

/**
 * Generierte lokale Inhalte (SPECIFICATIONS.md §56 bis §59).
 */

return [
    'admin' => [
        'title' => 'Lokale Inhalte',
        'intro' => 'Geben Sie die zu lesenden Seiten an, schreiben Sie Ihre Anweisung und starten Sie einen Test. Das System ergänzt Ort, Jahreszeit, Aufenthaltsdaten, Quellen und erwartetes Format.',
    ],
    'enabled' => 'Lokale Inhalte werden für kommende Aufenthalte erstellt.',
    'disabled' => 'Lokale Inhalte sind deaktiviert.',
    'not_configured' => 'Kein Anbieter konfiguriert: es wird keine Aktivität erstellt.',
    'configure' => 'Konfigurieren',
    'sources' => 'Gelesene Quellen',
    'no_source' => 'Keine Quelle. Fügen Sie mindestens eine öffentliche Seite hinzu.',
    'add_source' => 'Hinzufügen',
    'activate' => 'Aktivieren',
    'deactivate' => 'Deaktivieren',
    'source_added' => 'Quelle hinzugefügt.',
    'source_added_unresolved' => 'Quelle hinzugefügt, ihre Adresse antwortet aber noch nicht.',
    'source_updated' => 'Quelle aktualisiert.',
    'source_deleted' => 'Quelle gelöscht.',
    'prompt' => 'Anweisung',
    'prompt_intro' => 'Dieser Text gehört Ihnen. Das System ergänzt automatisch Ort, Jahreszeit, genaue Daten, Quellen und Ausgabeschema.',
    'prompt_saved' => 'Anweisung gespeichert.',
    'suggest_prompt' => 'Prompt aus dem Ort erzeugen',
    'run' => 'Generierung',
    'test' => 'Testen',
    'tested' => 'Test durchgeführt.',
    'refresh' => 'Kommende Aufenthalte aktualisieren',
    'refreshed' => 'Aufenthalte aktualisiert.',
    'nothing_due' => 'Kein Aufenthalt liegt im Fenster.',
    'runs' => 'Letzte Läufe',
    'no_run' => 'Noch kein Lauf.',
    'run_summary' => '{sources} Quelle(n), {items} Aktivität(en)',
    'window' => 'Die Generierung beginnt {weeks} Wochen vor der Anreise und wird danach alle {days} Tage aktualisiert.',
    'due' => '{count} Aufenthalt(e) zu aktualisieren.',
    'status' => [
        'running' => 'Läuft',
        'done' => 'Abgeschlossen',
        'failed' => 'Fehlgeschlagen',
    ],
    'field' => [
        'url' => 'Adresse der Seite',
        'label' => 'Bezeichnung',
        'prompt' => 'Ihre Anweisung',
    ],
    'source' => [
        'never_fetched' => 'Nie gelesen',
        'status' => [
            'ok' => 'Erfolgreich gelesen',
            'blocked' => 'Adresse abgelehnt',
            'empty' => 'Leere Seite',
        ],
    ],
    'category' => [
        'market' => 'Markt',
        'festival' => 'Fest',
        'museum' => 'Museum',
        'nature' => 'Natur',
        'sport' => 'Sport',
        'food' => 'Gastronomie',
        'other' => 'Sonstiges',
    ],
    'group' => [
        'book_ahead' => 'Vorab buchen',
        'this_week' => 'Während Ihres Aufenthalts',
    ],
    'verified_on' => 'geprüft am {date}',
    'stay' => [
        'title' => 'In Ihrer Nähe',
        'disclaimer' => 'Diese Vorschläge stammen aus den genannten Quellen und wurden am angegebenen Datum geprüft. Bestätigen Sie Zeiten und Verfügbarkeit beim Veranstalter.',
    ],
    'suggested_prompt' => 'Schlage Aktivitäten rund um {location} für die Gäste von {property} vor: Märkte, lokale Feste, Museen, Wanderungen und gute Adressen zum Essen. Bevorzuge, was zu Fuß oder innerhalb von dreißig Autominuten erreichbar ist, und weise darauf hin, was reserviert werden muss.',
    'error' => [
        'no_location' => 'Tragen Sie zuerst den Ort der Unterkunft in der Konfiguration ein.',
        'duplicate' => 'Diese Adresse steht bereits in der Liste.',
    ],
];
