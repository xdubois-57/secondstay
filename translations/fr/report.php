<?php

declare(strict_types=1);

/**
 * Reporting et export comptable (SPECIFICATIONS.md §66).
 */

return [
    'title' => 'Reporting',
    'disclaimer' => 'Ces chiffres comptent ce qui a été encaissé, attendu et détenu. Ils ne constituent ni un conseil '
        . 'fiscal ni une déclaration : leur lecture comptable relève de votre comptable.',
    'apply' => 'Afficher',
    'export' => 'Exporter en XLSX',
    'whole_year' => 'Année entière',
    'empty' => 'Aucun séjour sur cette période.',
    'period' => 'Période',
    'received' => 'Encaissé',
    'expected' => 'Attendu',
    'outstanding' => 'Reste à encaisser',
    'refunded' => 'Remboursé',
    'deposits_held' => 'Cautions détenues',
    'tourist_tax' => 'Taxe de séjour',
    'nights_sold' => 'Nuits vendues',
    'nights_available' => 'Nuits ouvertes',
    'occupancy' => 'Taux d’occupation',
    'average_night' => 'Prix moyen de la nuit',
    'stays' => 'Séjours',
    'field' => [
        'year' => 'Année',
        'month' => 'Mois',
        'metric' => 'Indicateur',
        'value' => 'Valeur',
        'arrival' => 'Arrivée',
        'departure' => 'Départ',
        'nights' => 'Nuits',
        'nights_in_period' => 'Nuits sur la période',
        'status' => 'État',
    ],
    'sheet' => [
        'summary' => 'Synthèse',
        'stays' => 'Séjours',
    ],
];
