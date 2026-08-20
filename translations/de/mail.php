<?php

declare(strict_types=1);

return [
    'footer' => [
        'automatic' => 'Diese Nachricht wurde automatisch gesendet. Sie können antworten: Ihre Antwort wird gelesen.',
    ],
    'account_confirmation' => [
        'subject' => 'Bestätigen Sie Ihre E-Mail-Adresse',
        'heading' => 'Hallo {first_name},',
        'intro' => 'Bestätigen Sie Ihre E-Mail-Adresse, um Ihr Konto zu aktivieren.',
        'button' => 'Meine Adresse bestätigen',
        'fallback' => 'Falls die Schaltfläche nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:',
        'ignore' => 'Falls Sie dies nicht angefordert haben, ignorieren Sie diese Nachricht.',
    ],
    'password_reset' => [
        'subject' => 'Passwort zurücksetzen',
        'heading' => 'Passwort zurücksetzen',
        'intro' => 'Dieser Link bleibt {hours} Stunde(n) gültig.',
        'button' => 'Neues Passwort wählen',
        'ignore' => 'Falls Sie dies nicht angefordert haben, bleibt Ihr Passwort unverändert.',
    ],
    'account_exists' => [
        'subject' => 'Ihr Konto existiert bereits',
        'heading' => 'Für diese Adresse existiert bereits ein Konto',
        'intro' => 'Jemand hat gerade versucht, ein Konto mit Ihrer Adresse zu erstellen. Wenn Sie das waren, melden Sie sich an oder setzen Sie Ihr Passwort zurück.',
        'button' => 'Mein Passwort zurücksetzen',
        'ignore' => 'Andernfalls ist keine Aktion erforderlich.',
    ],
    'verify' => [
        'ok' => 'SMTP-Verbindung erfolgreich.',
    ],
    'error' => [
        'not_configured' => 'Der E-Mail-Dienst ist nicht konfiguriert.',
        'connection_failed' => 'Verbindung zum SMTP-Server nicht möglich.',
        'tls_failed' => 'TLS-Verschlüsselung konnte nicht hergestellt werden.',
        'write_failed' => 'Schreiben an den SMTP-Server nicht möglich.',
        'no_response' => 'Der SMTP-Server hat nicht geantwortet.',
        'rejected' => 'Der SMTP-Server hat die Nachricht abgelehnt.',
        'unexpected_response' => 'Unerwartete SMTP-Antwort.',
    ],
    'waitlist_available' => [
        'subject' => 'Termine sind frei geworden',
        'heading' => 'Termine sind frei geworden',
        'intro' => 'Die Termine vom {arrival} bis {departure} sind soeben frei geworden.',
        'button' => 'Verfügbarkeit ansehen',
        'first_come' => 'Buchungen werden in der Reihenfolge des Eingangs bearbeitet: Diese Termine können jederzeit wieder belegt sein.',
    ],
];
