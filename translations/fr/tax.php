<?php

declare(strict_types=1);

/**
 * Taxe de séjour : barèmes datés et explication du calcul
 * (SPECIFICATIONS.md §63).
 */

return [
    'title' => 'Taxe de séjour',
    'intro' => 'Un barème est voté, prend effet à une date, puis est remplacé. Chaque règle porte donc sa période de '
        . 'validité, et un séjour déjà réservé conserve le barème qui s’appliquait à son arrivée.',
    'enabled' => 'La taxe de séjour est perçue.',
    'disabled' => 'La taxe de séjour n’est pas perçue.',
    'configure' => 'Configurer',
    'current' => 'En vigueur',
    'empty' => 'Aucun barème daté : la configuration tient lieu de barème courant.',
    'new_rule' => 'Nouveau barème',
    'rule_created' => 'Barème enregistré.',
    'rule_deleted' => 'Barème supprimé.',
    'overlap_warning' => 'Deux barèmes se recouvrent pour un même classement : le montant dépendrait de l’ordre des '
        . 'lignes.',
    'field' => [
        'period' => 'Période',
        'effective_from' => 'Prend effet le',
        'effective_to' => 'Jusqu’au',
        'effective_to_help' => 'Laisser vide tant qu’aucun barème suivant n’est connu.',
        'classification' => 'Classement',
        'territory' => 'Territoire',
        'per_adult_night' => 'Par adulte et par nuit',
        'cap' => 'Plafond par séjour',
        'taxable_from_age' => 'Taxable à partir de',
        'source' => 'Source officielle',
        'notes' => 'Note',
    ],
    'explain' => [
        'title' => 'Calcul de la taxe de séjour',
        'per_adult_night' => 'Par adulte et par nuit',
        'adults' => 'Adultes',
        'exempt' => 'Personnes exonérées',
        'nights' => 'Nuits',
        'cap' => 'Plafond appliqué',
        'total' => 'Total',
        'exemption_note' => 'Les mineurs sont exonérés (article L. 2333-31 du code général des collectivités '
            . 'territoriales).',
    ],
    'error' => [
        'effective_from' => 'La date de prise d’effet est obligatoire.',
        'period' => 'La date de fin ne peut pas précéder la date de prise d’effet.',
        'amount' => 'Les montants doivent être des nombres positifs.',
    ],
];
