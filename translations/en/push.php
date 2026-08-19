<?php

declare(strict_types=1);

/**
 * Erreurs du push navigateur : jamais de détail technique côté client.
 */

return [
    'error' => [
        'generic' => 'The notification could not be sent.',
        'not_configured' => 'Push notifications are not configured.',
        'invalid_endpoint' => 'Invalid notification address.',
        'invalid_subscription_key' => 'Invalid subscription key.',
        'invalid_key' => 'Invalid signing key.',
        'invalid_encoding' => 'Invalid encoding.',
        'invalid_salt' => 'Invalid encryption parameter.',
        'key_generation_failed' => 'Key generation failed.',
        'signature_failed' => 'Signing failed.',
        'encryption_failed' => 'The notification could not be encrypted.',
        'payload_too_large' => 'Notification too large.',
        'rejected' => 'The notification service refused the delivery.',
        'subscription_expired' => 'This device is no longer subscribed.',
        'transport' => 'The notification service is unreachable.',
        'too_many_devices' => 'Too many subscribed devices for this account.',
    ],
];
