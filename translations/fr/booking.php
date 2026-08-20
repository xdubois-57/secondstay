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
        'min_nights' => 'Séjour d’au moins {count} nuit|Séjour d’au moins {count} nuit|Séjour d’au moins {count} nuits',
        'max_guests' => 'Jusqu’à {count} voyageur|Jusqu’à {count} voyageur|Jusqu’à {count} voyageurs',
        'times' => 'Arrivée à partir de {checkin}, départ avant {checkout}',
        'fixed_week' => 'Séjours du samedi au samedi',
        'arrival_weekday' => 'Arrivée le {weekday} uniquement',
        'night_multiple' => 'Durée par tranches de {count} nuits',
    ],
    'calendar' => [
        'previous' => 'Mois précédent',
        'next' => 'Mois suivant',
        'caption' => 'Calendrier des disponibilités et des tarifs',
        'hint' => 'Sélectionnez une date d’arrivée puis une date de départ pour voir le total.',
        'state_free' => 'Libre',
        'state_blocked' => 'Occupé',
        'state_past' => 'Passé',
        'state_closed' => 'Non ouvert à la réservation',
    ],
    'quote' => [
        'title' => 'Votre séjour',
        'accommodation' => 'Hébergement',
        'cleaning' => 'Ménage',
        'total' => 'Total',
        'reset' => 'Recommencer la sélection',
        'nights' => '{count} nuit|{count} nuit|{count} nuits',
    ],
    'rates' => [
        'title' => 'Tarifs',
        'night' => 'Nuit (tarif de référence)',
        'cleaning' => 'Ménage',
        'cleaning_mandatory' => 'Inclus obligatoirement',
        'cleaning_optional' => 'À votre choix',
        'deposit' => 'Acompte à la réservation',
        'security_deposit' => 'Caution',
        'note' => 'Le tarif de certaines nuits peut différer du tarif de référence : le calendrier affiche le prix réel de chaque nuit.',
        'see_availability' => 'Voir les disponibilités',
    ],
    'rules' => [
        'title' => 'Règles de séjour',
    ],
    'error' => [
        'invalid_date' => 'Date invalide.',
        'invalid_range' => 'Les dates de séjour sont incohérentes.',
        'min_nights' => 'Le séjour est trop court.',
        'max_nights' => 'Le séjour est trop long.',
        'night_multiple' => 'La durée ne respecte pas les tranches autorisées.',
        'arrival_weekday' => 'Le jour d’arrivée n’est pas autorisé.',
        'departure_weekday' => 'Le jour de départ n’est pas autorisé.',
        'too_early' => 'Cette date est trop proche pour être réservée.',
        'too_far' => 'Le calendrier n’est pas encore ouvert à ces dates.',
        'unavailable' => 'Ces dates ne sont pas disponibles.',
        'min_adults' => 'Au moins un adulte est nécessaire.',
        'max_children' => 'Trop d’enfants pour ce logement.',
        'max_infants' => 'Trop de bébés pour ce logement.',
        'max_guests' => 'Le nombre de voyageurs dépasse la capacité du logement.',
    ],
];
