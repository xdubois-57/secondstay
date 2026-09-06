<?php

declare(strict_types=1);

return [
    'footer' => [
        'automatic' => 'Dit bericht is automatisch verzonden. U kunt antwoorden: uw antwoord wordt gelezen.',
    ],
    'account_confirmation' => [
        'subject' => 'Bevestig uw e-mailadres',
        'heading' => 'Hallo {first_name},',
        'intro' => 'Bevestig uw e-mailadres om uw account te activeren.',
        'button' => 'Mijn adres bevestigen',
        'fallback' => 'Werkt de knop niet, kopieer dan deze link in uw browser:',
        'ignore' => 'Als u dit niet hebt aangevraagd, kunt u dit bericht negeren.',
    ],
    'password_reset' => [
        'subject' => 'Uw wachtwoord opnieuw instellen',
        'heading' => 'Uw wachtwoord opnieuw instellen',
        'intro' => 'Deze link blijft {hours} uur geldig.',
        'button' => 'Nieuw wachtwoord kiezen',
        'ignore' => 'Als u dit niet hebt aangevraagd, blijft uw wachtwoord ongewijzigd.',
    ],
    'account_exists' => [
        'subject' => 'Uw account bestaat al',
        'heading' => 'Er bestaat al een account voor dit adres',
        'intro' => 'Iemand probeerde zojuist een account met uw adres aan te maken. Als u dat was, log in of stel uw '
            . 'wachtwoord opnieuw in.',
        'button' => 'Mijn wachtwoord resetten',
        'ignore' => 'Anders is geen actie nodig.',
    ],
    'verify' => [
        'ok' => 'SMTP-verbinding geslaagd.',
    ],
    'error' => [
        'not_configured' => 'De e-maildienst is niet geconfigureerd.',
        'connection_failed' => 'Verbinding met de SMTP-server mislukt.',
        'tls_failed' => 'TLS-versleuteling kon niet tot stand komen.',
        'write_failed' => 'Schrijven naar de SMTP-server mislukt.',
        'no_response' => 'De SMTP-server antwoordde niet.',
        'rejected' => 'De SMTP-server heeft het bericht geweigerd.',
        'unexpected_response' => 'Onverwacht SMTP-antwoord.',
    ],
    'waitlist_available' => [
        'subject' => 'Er zijn data vrijgekomen',
        'heading' => 'Er zijn data vrijgekomen',
        'intro' => 'De data van {arrival} tot {departure} zijn zojuist vrijgekomen.',
        'button' => 'Beschikbaarheid bekijken',
        'first_come' => 'Reserveringen worden op volgorde van binnenkomst behandeld: deze data kunnen op elk moment '
            . 'opnieuw bezet raken.',
    ],
];
