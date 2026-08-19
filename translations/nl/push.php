<?php

declare(strict_types=1);

/**
 * Erreurs du push navigateur : jamais de détail technique côté client.
 */

return [
    'error' => [
        'generic' => 'De melding kon niet worden verzonden.',
        'not_configured' => 'Pushmeldingen zijn niet geconfigureerd.',
        'invalid_endpoint' => 'Ongeldig meldingsadres.',
        'invalid_subscription_key' => 'Ongeldige abonnementssleutel.',
        'invalid_key' => 'Ongeldige ondertekeningssleutel.',
        'invalid_encoding' => 'Ongeldige codering.',
        'invalid_salt' => 'Ongeldige versleutelingsparameter.',
        'key_generation_failed' => 'Sleutel aanmaken mislukt.',
        'signature_failed' => 'Ondertekenen mislukt.',
        'encryption_failed' => 'De melding kon niet worden versleuteld.',
        'payload_too_large' => 'Melding te groot.',
        'rejected' => 'De meldingsdienst heeft de verzending geweigerd.',
        'subscription_expired' => 'Dit apparaat is niet langer geabonneerd.',
        'transport' => 'De meldingsdienst is onbereikbaar.',
        'too_many_devices' => 'Te veel geabonneerde apparaten voor dit account.',
    ],
];
