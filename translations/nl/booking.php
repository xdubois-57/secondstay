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
        'min_nights' => 'Verblijf van minstens {count} nacht|Verblijf van minstens {count} nacht|Verblijf van minstens {count} nachten',
        'max_guests' => 'Tot {count} gast|Tot {count} gast|Tot {count} gasten',
        'times' => 'Aankomst vanaf {checkin}, vertrek vóór {checkout}',
        'fixed_week' => 'Verblijven van zaterdag tot zaterdag',
        'arrival_weekday' => 'Aankomst alleen op {weekday}',
        'night_multiple' => 'Duur in blokken van {count} nachten',
    ],
    'calendar' => [
        'previous' => 'Vorige maand',
        'next' => 'Volgende maand',
        'caption' => 'Kalender met beschikbaarheid en tarieven',
        'hint' => 'Kies een aankomstdatum en daarna een vertrekdatum om het totaal te zien.',
        'state_free' => 'Vrij',
        'state_blocked' => 'Bezet',
        'state_past' => 'Voorbij',
        'state_closed' => 'Nog niet reserveerbaar',
    ],
    'quote' => [
        'title' => 'Uw verblijf',
        'accommodation' => 'Verblijf',
        'cleaning' => 'Schoonmaak',
        'total' => 'Totaal',
        'reset' => 'Selectie opnieuw beginnen',
        'nights' => '{count} nacht|{count} nacht|{count} nachten',
    ],
    'rates' => [
        'title' => 'Tarieven',
        'night' => 'Nacht (referentietarief)',
        'cleaning' => 'Schoonmaak',
        'cleaning_mandatory' => 'Altijd inbegrepen',
        'cleaning_optional' => 'Naar keuze',
        'deposit' => 'Aanbetaling bij reservering',
        'security_deposit' => 'Waarborg',
        'note' => 'Sommige nachten kunnen afwijken van het referentietarief: de kalender toont de werkelijke prijs van elke nacht.',
        'see_availability' => 'Beschikbaarheid bekijken',
    ],
    'rules' => [
        'title' => 'Verblijfsregels',
    ],
    'error' => [
        'invalid_date' => 'Ongeldige datum.',
        'invalid_range' => 'De verblijfsdata zijn tegenstrijdig.',
        'min_nights' => 'Het verblijf is te kort.',
        'max_nights' => 'Het verblijf is te lang.',
        'night_multiple' => 'De duur past niet in de toegestane blokken.',
        'arrival_weekday' => 'Die aankomstdag is niet toegestaan.',
        'departure_weekday' => 'Die vertrekdag is niet toegestaan.',
        'too_early' => 'Die datum ligt te dichtbij om te reserveren.',
        'too_far' => 'De kalender is nog niet zo ver vooruit geopend.',
        'unavailable' => 'Die data zijn niet beschikbaar.',
        'min_adults' => 'Er is minstens één volwassene nodig.',
        'max_children' => 'Te veel kinderen voor deze woning.',
        'max_infants' => 'Te veel baby’s voor deze woning.',
        'max_guests' => 'Het aantal gasten overschrijdt de capaciteit van de woning.',
    ],
];
