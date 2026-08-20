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
        'min_nights' => 'Stays of at least {count} night|Stays of at least {count} night|Stays of at least {count} nights',
        'max_guests' => 'Up to {count} guest|Up to {count} guest|Up to {count} guests',
        'times' => 'Arrival from {checkin}, departure before {checkout}',
        'fixed_week' => 'Saturday to Saturday stays',
        'arrival_weekday' => 'Arrival on {weekday} only',
        'night_multiple' => 'Length in blocks of {count} nights',
    ],
    'calendar' => [
        'previous' => 'Previous month',
        'next' => 'Next month',
        'caption' => 'Availability and rate calendar',
        'hint' => 'Pick an arrival date then a departure date to see the total.',
        'state_free' => 'Free',
        'state_blocked' => 'Booked',
        'state_past' => 'Past',
        'state_closed' => 'Not open for booking',
    ],
    'quote' => [
        'title' => 'Your stay',
        'accommodation' => 'Accommodation',
        'cleaning' => 'Cleaning',
        'total' => 'Total',
        'reset' => 'Start the selection again',
        'nights' => '{count} night|{count} night|{count} nights',
    ],
    'rates' => [
        'title' => 'Rates',
        'night' => 'Night (reference rate)',
        'cleaning' => 'Cleaning',
        'cleaning_mandatory' => 'Always included',
        'cleaning_optional' => 'Your choice',
        'deposit' => 'Deposit at booking',
        'security_deposit' => 'Security deposit',
        'note' => 'Some nights may differ from the reference rate: the calendar shows the real price of every night.',
        'see_availability' => 'See availability',
    ],
    'rules' => [
        'title' => 'Stay rules',
    ],
    'error' => [
        'invalid_date' => 'Invalid date.',
        'invalid_range' => 'The stay dates are inconsistent.',
        'min_nights' => 'The stay is too short.',
        'max_nights' => 'The stay is too long.',
        'night_multiple' => 'The length does not match the allowed blocks.',
        'arrival_weekday' => 'That arrival day is not allowed.',
        'departure_weekday' => 'That departure day is not allowed.',
        'too_early' => 'That date is too soon to be booked.',
        'too_far' => 'The calendar is not open that far ahead yet.',
        'unavailable' => 'Those dates are not available.',
        'min_adults' => 'At least one adult is required.',
        'max_children' => 'Too many children for this property.',
        'max_infants' => 'Too many infants for this property.',
        'max_guests' => 'The number of guests exceeds the property capacity.',
    ],
];
