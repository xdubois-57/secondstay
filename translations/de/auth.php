<?php

declare(strict_types=1);

return [
    'login' => [
        'title' => 'Anmelden',
        'action' => 'Anmelden',
        'welcome' => 'Sie sind angemeldet.',
        'invalid_credentials' => 'E-Mail-Adresse oder Passwort ist falsch.',
        'rate_limited' => 'Zu viele Versuche. Bitte in einigen Minuten erneut versuchen.',
        'account_pending' => 'Dieses Konto ist noch nicht aktiviert.',
        'or' => 'oder',
        'account_suspended' => 'Dieses Konto ist gesperrt.',
    ],
    'logout' => [
        'action' => 'Abmelden',
        'done' => 'Sie sind abgemeldet.',
    ],
    'field' => [
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
        'password_confirm' => 'Passwort bestätigen',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'phone' => 'Telefon',
    ],
    'password' => [
        'strength' => 'Passwortstärke',
        'requirements' => 'Mindestens {length} Zeichen, mit Groß- und Kleinbuchstabe sowie Ziffer.',
        'too_short' => 'Das Passwort ist zu kurz.',
        'needs_uppercase' => 'Fügen Sie mindestens einen Großbuchstaben hinzu.',
        'needs_lowercase' => 'Fügen Sie mindestens einen Kleinbuchstaben hinzu.',
        'needs_digit' => 'Fügen Sie mindestens eine Ziffer hinzu.',
        'too_repetitive' => 'Das Passwort ist zu eintönig.',
    ],
    'role' => [
        'customer' => 'Gast',
        'local_manager' => 'Objektbetreuung',
        'administrator' => 'Administrator',
    ],
];
