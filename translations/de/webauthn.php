<?php

declare(strict_types=1);

return [
    'error' => [
        'generic' => 'Der Passkey konnte nicht überprüft werden.',
        'unsupported_algorithm' => 'Nicht unterstützter Schlüsselalgorithmus.',
        'invalid_key' => 'Ungültiger öffentlicher Schlüssel.',
        'invalid_attestation' => 'Ungültige Attestierung.',
        'invalid_authenticator_data' => 'Ungültige Authenticator-Daten.',
        'invalid_client_data' => 'Ungültige Client-Daten.',
        'invalid_encoding' => 'Ungültige Kodierung.',
        'relying_party_mismatch' => 'Die Website stimmt nicht mit dem Schlüssel überein.',
        'origin_mismatch' => 'Nicht zulässiger Ursprung.',
        'cross_origin' => 'Cross-Origin-Anfrage abgelehnt.',
        'type_mismatch' => 'Unerwarteter Vorgangstyp.',
        'challenge_mismatch' => 'Ungültige Sicherheitsabfrage.',
        'challenge_expired' => 'Die Zeit ist abgelaufen, bitte erneut versuchen.',
        'no_challenge' => 'Keine laufende Anfrage.',
        'user_not_present' => 'Benutzerbestätigung fehlt.',
        'no_credential' => 'Kein Passkey angegeben.',
        'unknown_credential' => 'Unbekannter Passkey.',
        'already_registered' => 'Dieser Passkey ist bereits registriert.',
        'bad_signature' => 'Ungültige Signatur.',
        'counter_replay' => 'Inkonsistenter Signaturzähler: Der Schlüssel könnte geklont sein.',
    ],
];
