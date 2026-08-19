<?php

declare(strict_types=1);

return [
    'error' => [
        'generic' => 'De passkey kon niet worden geverifieerd.',
        'unsupported_algorithm' => 'Niet-ondersteund sleutelalgoritme.',
        'invalid_key' => 'Ongeldige publieke sleutel.',
        'invalid_attestation' => 'Ongeldige attestatie.',
        'invalid_authenticator_data' => 'Ongeldige authenticatorgegevens.',
        'invalid_client_data' => 'Ongeldige clientgegevens.',
        'invalid_encoding' => 'Ongeldige codering.',
        'relying_party_mismatch' => 'De site komt niet overeen met de sleutel.',
        'origin_mismatch' => 'Niet-toegestane oorsprong.',
        'cross_origin' => 'Cross-origin-verzoek geweigerd.',
        'type_mismatch' => 'Onverwacht bewerkingstype.',
        'challenge_mismatch' => 'Ongeldige beveiligingsuitdaging.',
        'challenge_expired' => 'De tijd is verstreken, probeer opnieuw.',
        'no_challenge' => 'Geen lopende aanvraag.',
        'user_not_present' => 'Gebruikersbevestiging ontbreekt.',
        'no_credential' => 'Geen passkey opgegeven.',
        'unknown_credential' => 'Onbekende passkey.',
        'already_registered' => 'Deze passkey is al geregistreerd.',
        'bad_signature' => 'Ongeldige handtekening.',
        'counter_replay' => 'Inconsistente handtekeningteller: de sleutel kan gekloond zijn.',
    ],
];
