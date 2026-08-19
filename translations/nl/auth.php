<?php

declare(strict_types=1);

return [
    'login' => [
        'title' => 'Inloggen',
        'action' => 'Inloggen',
        'welcome' => 'U bent ingelogd.',
        'invalid_credentials' => 'Onjuist e-mailadres of wachtwoord.',
        'rate_limited' => 'Te veel pogingen. Probeer het over enkele minuten opnieuw.',
        'account_pending' => 'Dit account is nog niet geactiveerd.',
        'account_suspended' => 'Dit account is geschorst.',
    ],
    'logout' => [
        'action' => 'Uitloggen',
        'done' => 'U bent uitgelogd.',
    ],
    'field' => [
        'email' => 'E-mailadres',
        'password' => 'Wachtwoord',
        'password_confirm' => 'Wachtwoord bevestigen',
        'first_name' => 'Voornaam',
        'last_name' => 'Achternaam',
        'phone' => 'Telefoon',
    ],
    'password' => [
        'strength' => 'Wachtwoordsterkte',
        'requirements' => 'Minstens {length} tekens, met een hoofdletter, een kleine letter en een cijfer.',
        'too_short' => 'Het wachtwoord is te kort.',
        'needs_uppercase' => 'Voeg minstens één hoofdletter toe.',
        'needs_lowercase' => 'Voeg minstens één kleine letter toe.',
        'needs_digit' => 'Voeg minstens één cijfer toe.',
        'too_repetitive' => 'Het wachtwoord is te repetitief.',
    ],
    'role' => [
        'customer' => 'Gast',
        'local_manager' => 'Lokale beheerder',
        'administrator' => 'Beheerder',
    ],
];
