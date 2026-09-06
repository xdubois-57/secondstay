<?php

declare(strict_types=1);

/**
 * Rapportage en boekhoudkundige export (SPECIFICATIONS.md §66).
 */

return [
    'title' => 'Rapportage',
    'disclaimer' => 'Deze cijfers tellen wat is ontvangen, verwacht en ingehouden. Ze vormen geen fiscaal advies en '
        . 'geen aangifte: de boekhoudkundige lezing is de taak van uw boekhouder.',
    'apply' => 'Tonen',
    'export' => 'Exporteren naar XLSX',
    'whole_year' => 'Heel het jaar',
    'empty' => 'Geen verblijf in deze periode.',
    'period' => 'Periode',
    'received' => 'Ontvangen',
    'expected' => 'Verwacht',
    'outstanding' => 'Nog te ontvangen',
    'refunded' => 'Terugbetaald',
    'deposits_held' => 'Ingehouden waarborgen',
    'tourist_tax' => 'Toeristenbelasting',
    'nights_sold' => 'Verkochte nachten',
    'nights_available' => 'Opengestelde nachten',
    'occupancy' => 'Bezettingsgraad',
    'average_night' => 'Gemiddelde nachtprijs',
    'stays' => 'Verblijven',
    'field' => [
        'year' => 'Jaar',
        'month' => 'Maand',
        'metric' => 'Indicator',
        'value' => 'Waarde',
        'arrival' => 'Aankomst',
        'departure' => 'Vertrek',
        'nights' => 'Nachten',
        'nights_in_period' => 'Nachten in de periode',
        'status' => 'Status',
    ],
    'sheet' => [
        'summary' => 'Overzicht',
        'stays' => 'Verblijven',
    ],
];
