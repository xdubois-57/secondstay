<?php

declare(strict_types=1);

/**
 * Plaatsbeschrijvingen bij aankomst en vertrek (SPECIFICATIONS.md §53).
 */

return [
    'title' => 'Plaatsbeschrijvingen',
    'kind' => [
        'checkin' => 'Plaatsbeschrijving bij aankomst',
        'checkout' => 'Plaatsbeschrijving bij vertrek',
    ],
    'state' => [
        'legend' => 'Vaststelling',
        'pending' => 'Te controleren',
        'ok' => 'In orde',
        'anomaly' => 'Afwijking',
    ],
    'status' => [
        'open' => 'Bezig',
        'completed' => 'Afgerond',
    ],
    'zone' => [
        'entrance' => 'Inkom',
        'living_room' => 'Woonkamer',
        'kitchen' => 'Keuken',
        'bedrooms' => 'Slaapkamers',
        'bathrooms' => 'Badkamers',
        'outdoor' => 'Buitenruimte',
        'meters' => 'Meters',
    ],
    'checkin_intro' => 'Meld binnen {hours} uur na uw aankomst wat niet in orde is. Is alles in orde, dan hoeft u niets te doen.',
    'checkout_intro' => 'Bij vertrek is een foto verplicht voor elke zone die daarom vraagt.',
    'note' => 'Opmerking',
    'photo' => 'Foto',
    'photo_required' => 'Foto verplicht',
    'photo_n' => 'Foto {index}',
    'save' => 'Zone opslaan',
    'complete' => 'Plaatsbeschrijving afronden',
    'saved' => 'Zone opgeslagen.',
    'completed' => 'Plaatsbeschrijving afgerond.',
    'open_incident' => 'Incident melden',
    'no_zone' => 'Er is nog geen zone bepaald voor deze woning.',
    'done_on' => 'Afgerond op {date}.',
    'not_started' => 'Niet begonnen',
    'not_started_help' => 'Voor dit moment van het verblijf is nog niets vastgelegd.',
    'error' => [
        'completed' => 'Deze plaatsbeschrijving is afgerond en kan niet meer worden gewijzigd.',
        'unknown_zone' => 'Onbekende zone.',
        'not_a_photo' => 'Hier worden alleen foto’s aanvaard.',
        'photos_required' => 'Foto’s van de vereiste zones zijn verplicht bij vertrek.',
        'incomplete' => 'Elke zone moet ingevuld zijn.',
        'not_an_anomaly' => 'Een incident kan alleen worden geopend op een zone met een afwijking.',
        'code' => 'De code van de zone is verplicht.',
    ],
    'admin' => [
        'title' => 'Zones en referentiefoto’s',
        'intro' => 'Bepaal de zones van de woning, hun volgorde, hun instructies en welke bij vertrek een foto vereisen.',
        'completeness' => 'Aangepaste namen',
        'completeness_help' => 'Een zone zonder aangepaste naam gebruikt het ingebouwde label, dat al in de vier talen bestaat.',
        'no_zone' => 'Geen zone bepaald.',
        'seed' => 'De voorgestelde zones aanmaken',
        'seeded' => 'Voorgestelde zones aangemaakt.',
        'already_seeded' => 'Er bestaan al zones: er is niets aangemaakt.',
        'saved' => 'Zone opgeslagen.',
        'reference_added' => 'Referentiefoto toegevoegd.',
        'name' => 'Naam van de zone',
        'position' => 'Volgorde',
        'instructions' => 'Instructies',
        'reference_note' => 'Referentienota',
        'active' => 'Actief',
        'reference_photos' => 'Referentiefoto’s',
        'no_reference' => 'Geen referentiefoto.',
        'add_reference' => 'Een referentiefoto toevoegen',
        'new_zone' => 'Nieuwe zone',
        'code' => 'Code',
        'code_help' => 'Stabiele identificatie in kleine letters, onafhankelijk van de taal.',
        'detail' => 'Details bekijken',
    ],
];
