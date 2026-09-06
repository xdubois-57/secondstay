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
    'import' => [
        'title' => 'Geïmporteerde externe agenda’s',
        'intro' => 'Nachten die op een ander platform zijn verkocht, worden onbeschikbare data. Een geïmporteerde feed '
            . 'maakt nooit een reservering aan en raakt handmatig ingevoerde blokkades nooit aan.',
        'url' => 'Adres van de feed',
        'label' => 'Label',
        'provider_label' => 'Platform',
        'last_sync' => 'Laatste synchronisatie',
        'events' => 'Geblokkeerde nachten',
        'empty' => 'Geen externe agenda geïmporteerd.',
        'never_synced' => 'Nooit gesynchroniseerd',
        'added' => 'Externe agenda toegevoegd. Start een synchronisatie om de nachten te importeren.',
        'deleted' => 'Externe agenda verwijderd, samen met de blokkades die eruit voortkwamen.',
        'synced' => 'Externe agenda’s gesynchroniseerd.',
        'partial' => 'Sommige externe agenda’s antwoordden niet; bestaande blokkades blijven behouden.',
        'nothing' => 'Geen actieve externe agenda om te synchroniseren.',
        'action' => [
            'add' => 'Toevoegen',
            'sync' => 'Synchroniseren',
            'delete' => 'Verwijderen',
        ],
        'provider' => [
            'airbnb' => 'Airbnb',
            'booking' => 'Booking.com',
            'abritel' => 'Abritel',
            'other' => 'Andere',
        ],
        'status' => [
            'ok' => 'Bijgewerkt',
            'blocked' => 'Adres geweigerd',
            'not_a_calendar' => 'Geen agenda',
            'unavailable' => 'Niet beschikbaar',
        ],
        'error' => [
            'duplicate' => 'Deze feed is al geïmporteerd.',
            'inactive' => 'Deze agenda is uitgeschakeld.',
            'blocked' => 'Adres geweigerd: de feed kan niet worden opgehaald.',
            'not_a_calendar' => 'Het antwoord is geen iCalendar-feed.',
            'unavailable' => 'Feed niet beschikbaar: de synchronisatie is mislukt.',
        ],
    ],
    'error' => [
        'not_found' => 'Kalenderlink niet gevonden.',
        'disabled' => 'Privékalenders staan uit.',
    ],
];
