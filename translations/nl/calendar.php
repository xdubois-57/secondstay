<?php

declare(strict_types=1);

/**
 * Privékalenders in iCalendar-formaat.
 */

return [
    'title' => 'Privékalenders',
    'intro' => 'Deze adressen zijn persoonlijk: ze geven toegang tot de kalender zonder wachtwoord. Deel ze niet.',
    'scope' => [
        'admin' => 'Beheer',
        'manager' => 'Lokale beheerder',
        'customer' => 'Mijn verblijf',
    ],
    'feed' => [
        'admin' => 'Verblijven — {property}',
        'manager' => 'Interventies — {property}',
        'customer' => 'Mijn verblijf — {property}',
    ],
    'event' => [
        'stay' => 'Verblijf {reference}',
    ],
    'action' => [
        'create' => 'Link aanmaken',
        'revoke' => 'Intrekken',
        'copy' => 'Kalenderadres',
        'subscribe' => 'Op de kalender abonneren',
    ],
    'created' => 'Kalenderlink aangemaakt. Kopieer hem nu: hij wordt niet opnieuw getoond.',
    'revoked' => 'Kalenderlink ingetrokken.',
    'once' => 'Dit adres wordt maar één keer getoond.',
    'never_used' => 'Nooit gebruikt',
    'last_used' => 'Laatst gebruikt',
    'label' => 'Label',
    'created_at' => 'Aangemaakt op',
    'empty' => 'Geen actieve kalenderlink.',
    'error' => [
        'not_found' => 'Kalenderlink niet gevonden.',
        'disabled' => 'Privékalenders staan uit.',
    ],
];
