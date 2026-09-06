<?php

declare(strict_types=1);

/**
 * Übergabeprotokolle bei An- und Abreise (SPECIFICATIONS.md §53).
 */

return [
    'title' => 'Übergabeprotokolle',
    'kind' => [
        'checkin' => 'Übergabeprotokoll bei Anreise',
        'checkout' => 'Übergabeprotokoll bei Abreise',
    ],
    'state' => [
        'legend' => 'Feststellung',
        'pending' => 'Zu prüfen',
        'ok' => 'In Ordnung',
        'anomaly' => 'Abweichung',
    ],
    'status' => [
        'open' => 'Läuft',
        'completed' => 'Abgeschlossen',
    ],
    'zone' => [
        'entrance' => 'Eingang',
        'living_room' => 'Wohnzimmer',
        'kitchen' => 'Küche',
        'bedrooms' => 'Schlafzimmer',
        'bathrooms' => 'Badezimmer',
        'outdoor' => 'Außenbereich',
        'meters' => 'Zähler',
    ],
    'checkin_intro' => 'Melden Sie innerhalb von {hours} Stunden nach Ihrer Anreise, was nicht in Ordnung ist. Ist '
        . 'alles in Ordnung, müssen Sie nichts tun.',
    'checkout_intro' => 'Bei der Abreise ist für jeden entsprechend gekennzeichneten Bereich ein Foto verpflichtend.',
    'note' => 'Anmerkung',
    'photo' => 'Foto',
    'photo_required' => 'Foto verpflichtend',
    'photo_n' => 'Foto {index}',
    'save' => 'Bereich speichern',
    'complete' => 'Protokoll abschließen',
    'saved' => 'Bereich gespeichert.',
    'completed' => 'Übergabeprotokoll abgeschlossen.',
    'open_incident' => 'Vorfall melden',
    'no_zone' => 'Für diese Unterkunft ist noch kein Bereich festgelegt.',
    'done_on' => 'Abgeschlossen am {date}.',
    'not_started' => 'Nicht begonnen',
    'not_started_help' => 'Für diesen Zeitpunkt des Aufenthalts wurde noch nichts erfasst.',
    'error' => [
        'completed' => 'Dieses Protokoll ist abgeschlossen und kann nicht mehr geändert werden.',
        'unknown_zone' => 'Unbekannter Bereich.',
        'not_a_photo' => 'Hier werden nur Fotos angenommen.',
        'photos_required' => 'Fotos der erforderlichen Bereiche sind bei der Abreise verpflichtend.',
        'incomplete' => 'Jeder Bereich muss ausgefüllt sein.',
        'not_an_anomaly' => 'Ein Vorfall kann nur zu einem Bereich mit Abweichung eröffnet werden.',
        'code' => 'Der Code des Bereichs ist erforderlich.',
    ],
    'admin' => [
        'title' => 'Bereiche und Referenzfotos',
        'intro' => 'Legen Sie die Bereiche der Unterkunft fest: Reihenfolge, Hinweise und welche bei der Abreise ein '
            . 'Foto verlangen.',
        'completeness' => 'Eigene Bezeichnungen',
        'completeness_help' => 'Ein Bereich ohne eigene Bezeichnung verwendet die eingebaute Beschriftung, die bereits '
            . 'in allen vier Sprachen vorliegt.',
        'no_zone' => 'Kein Bereich festgelegt.',
        'seed' => 'Vorgeschlagene Bereiche anlegen',
        'seeded' => 'Vorgeschlagene Bereiche angelegt.',
        'already_seeded' => 'Es bestehen bereits Bereiche: es wurde nichts angelegt.',
        'saved' => 'Bereich gespeichert.',
        'reference_added' => 'Referenzfoto hinzugefügt.',
        'name' => 'Name des Bereichs',
        'position' => 'Reihenfolge',
        'instructions' => 'Hinweise',
        'reference_note' => 'Referenzhinweis',
        'active' => 'Aktiv',
        'reference_photos' => 'Referenzfotos',
        'no_reference' => 'Kein Referenzfoto.',
        'add_reference' => 'Referenzfoto hinzufügen',
        'new_zone' => 'Neuer Bereich',
        'code' => 'Code',
        'code_help' => 'Stabile Kennung in Kleinbuchstaben, unabhängig von der Sprache.',
        'detail' => 'Details ansehen',
    ],
];
