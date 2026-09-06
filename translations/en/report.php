<?php

declare(strict_types=1);

/**
 * Reporting and accounting export (SPECIFICATIONS.md §66).
 */

return [
    'title' => 'Reporting',
    'disclaimer' => 'These figures count what has been received, expected and held. They are neither tax advice nor a '
        . 'declaration: their accounting reading is your accountant’s business.',
    'apply' => 'Show',
    'export' => 'Export to XLSX',
    'whole_year' => 'Whole year',
    'empty' => 'No stay in this period.',
    'period' => 'Period',
    'received' => 'Received',
    'expected' => 'Expected',
    'outstanding' => 'Still to receive',
    'refunded' => 'Refunded',
    'deposits_held' => 'Deposits held',
    'tourist_tax' => 'Tourist tax',
    'nights_sold' => 'Nights sold',
    'nights_available' => 'Nights open',
    'occupancy' => 'Occupancy rate',
    'average_night' => 'Average nightly price',
    'stays' => 'Stays',
    'field' => [
        'year' => 'Year',
        'month' => 'Month',
        'metric' => 'Metric',
        'value' => 'Value',
        'arrival' => 'Arrival',
        'departure' => 'Departure',
        'nights' => 'Nights',
        'nights_in_period' => 'Nights in period',
        'status' => 'State',
    ],
    'sheet' => [
        'summary' => 'Summary',
        'stays' => 'Stays',
    ],
];
