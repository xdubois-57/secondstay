<?php

declare(strict_types=1);

/**
 * Disponibilites, regles de sejour et tarifs.
 *
 * Les montants sont formates par le Formatter : ce catalogue ne contient
 * jamais de symbole monetaire ni de format de date.
 */

return [
    'rule' => [
        'min_nights' => 'Aufenthalt von mindestens {count} Nacht|Aufenthalt von mindestens {count} Nacht|Aufenthalt von mindestens {count} Nächten',
        'max_guests' => 'Bis zu {count} Gast|Bis zu {count} Gast|Bis zu {count} Gästen',
        'times' => 'Anreise ab {checkin}, Abreise vor {checkout}',
        'fixed_week' => 'Aufenthalte von Samstag bis Samstag',
        'arrival_weekday' => 'Anreise nur am {weekday}',
        'night_multiple' => 'Dauer in Blöcken von {count} Nächten',
    ],
    'calendar' => [
        'previous' => 'Vorheriger Monat',
        'next' => 'Nächster Monat',
        'caption' => 'Kalender mit Verfügbarkeit und Preisen',
        'hint' => 'Wählen Sie ein Anreisedatum und danach ein Abreisedatum, um die Summe zu sehen.',
        'state_free' => 'Frei',
        'state_blocked' => 'Belegt',
        'state_past' => 'Vergangen',
        'state_closed' => 'Noch nicht buchbar',
    ],
    'quote' => [
        'title' => 'Ihr Aufenthalt',
        'accommodation' => 'Unterkunft',
        'cleaning' => 'Endreinigung',
        'total' => 'Gesamt',
        'reset' => 'Auswahl neu beginnen',
        'nights' => '{count} Nacht|{count} Nacht|{count} Nächte',
    ],
    'rates' => [
        'title' => 'Preise',
        'night' => 'Nacht (Referenzpreis)',
        'cleaning' => 'Endreinigung',
        'cleaning_mandatory' => 'Immer enthalten',
        'cleaning_optional' => 'Nach Wahl',
        'deposit' => 'Anzahlung bei Buchung',
        'security_deposit' => 'Kaution',
        'note' => 'Einzelne Nächte können vom Referenzpreis abweichen: Der Kalender zeigt den tatsächlichen Preis jeder Nacht.',
        'see_availability' => 'Verfügbarkeit ansehen',
    ],
    'rules' => [
        'title' => 'Aufenthaltsregeln',
    ],
    'error' => [
        'invalid_date' => 'Ungültiges Datum.',
        'invalid_range' => 'Die Aufenthaltsdaten sind widersprüchlich.',
        'min_nights' => 'Der Aufenthalt ist zu kurz.',
        'max_nights' => 'Der Aufenthalt ist zu lang.',
        'night_multiple' => 'Die Dauer passt nicht zu den zulässigen Blöcken.',
        'arrival_weekday' => 'Dieser Anreisetag ist nicht zulässig.',
        'departure_weekday' => 'Dieser Abreisetag ist nicht zulässig.',
        'too_early' => 'Dieses Datum liegt zu nah, um gebucht zu werden.',
        'too_far' => 'Der Kalender ist für diesen Zeitraum noch nicht geöffnet.',
        'unavailable' => 'Diese Daten sind nicht verfügbar.',
        'min_adults' => 'Mindestens eine erwachsene Person ist erforderlich.',
        'max_children' => 'Zu viele Kinder für diese Unterkunft.',
        'max_infants' => 'Zu viele Kleinkinder für diese Unterkunft.',
        'max_guests' => 'Die Gästezahl übersteigt die Kapazität der Unterkunft.',
    ],
];
