<?php

declare(strict_types=1);

/**
 * Erreurs du push navigateur : jamais de détail technique côté client.
 */

return [
    'error' => [
        'generic' => 'La notification n’a pas pu être envoyée.',
        'not_configured' => 'Les notifications push ne sont pas configurées.',
        'invalid_endpoint' => 'Adresse de notification invalide.',
        'invalid_subscription_key' => 'Clé d’abonnement invalide.',
        'invalid_key' => 'Clé de signature invalide.',
        'invalid_encoding' => 'Encodage invalide.',
        'invalid_salt' => 'Paramètre de chiffrement invalide.',
        'key_generation_failed' => 'Génération de clé impossible.',
        'signature_failed' => 'Signature impossible.',
        'encryption_failed' => 'Chiffrement de la notification impossible.',
        'payload_too_large' => 'Notification trop volumineuse.',
        'rejected' => 'Le service de notification a refusé l’envoi.',
        'subscription_expired' => 'Cet appareil n’est plus abonné.',
        'transport' => 'Le service de notification est injoignable.',
        'too_many_devices' => 'Trop d’appareils abonnés pour ce compte.',
    ],
];
