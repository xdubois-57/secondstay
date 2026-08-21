<?php

declare(strict_types=1);

/**
 * Kurtaxe: datierte Tarife und Erläuterung der Berechnung
 * (SPECIFICATIONS.md §63).
 */

return [
    'title' => 'Kurtaxe',
    'intro' => 'Ein Tarif wird beschlossen, tritt zu einem Datum in Kraft und wird später ersetzt. Jede Regel trägt daher ihren Gültigkeitszeitraum, und ein bereits gebuchter Aufenthalt behält den bei seiner Anreise geltenden Tarif.',
    'enabled' => 'Die Kurtaxe wird erhoben.',
    'disabled' => 'Die Kurtaxe wird nicht erhoben.',
    'configure' => 'Konfigurieren',
    'current' => 'In Kraft',
    'empty' => 'Kein datierter Tarif: die Konfiguration gilt als aktueller Tarif.',
    'new_rule' => 'Neuer Tarif',
    'rule_created' => 'Tarif gespeichert.',
    'rule_deleted' => 'Tarif gelöscht.',
    'overlap_warning' => 'Zwei Tarife überschneiden sich für dieselbe Einstufung: der Betrag hinge von der Reihenfolge der Zeilen ab.',
    'field' => [
        'period' => 'Zeitraum',
        'effective_from' => 'Tritt in Kraft am',
        'effective_to' => 'Bis',
        'effective_to_help' => 'Leer lassen, solange kein Folgetarif bekannt ist.',
        'classification' => 'Einstufung',
        'territory' => 'Gebiet',
        'per_adult_night' => 'Pro Erwachsener und Nacht',
        'cap' => 'Höchstbetrag pro Aufenthalt',
        'taxable_from_age' => 'Steuerpflichtig ab',
        'source' => 'Offizielle Quelle',
        'notes' => 'Notiz',
    ],
    'explain' => [
        'title' => 'Berechnung der Kurtaxe',
        'per_adult_night' => 'Pro Erwachsener und Nacht',
        'adults' => 'Erwachsene',
        'exempt' => 'Befreite Personen',
        'nights' => 'Nächte',
        'cap' => 'Angewandter Höchstbetrag',
        'total' => 'Gesamt',
        'exemption_note' => 'Minderjährige sind befreit (Artikel L. 2333-31 des französischen Code général des collectivités territoriales).',
    ],
    'error' => [
        'effective_from' => 'Das Datum des Inkrafttretens ist erforderlich.',
        'period' => 'Das Enddatum darf nicht vor dem Datum des Inkrafttretens liegen.',
        'amount' => 'Beträge müssen positive Zahlen sein.',
    ],
];
