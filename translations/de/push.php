<?php

declare(strict_types=1);

/**
 * Erreurs du push navigateur : jamais de détail technique côté client.
 */

return [
    'error' => [
        'generic' => 'Die Benachrichtigung konnte nicht gesendet werden.',
        'not_configured' => 'Push-Benachrichtigungen sind nicht konfiguriert.',
        'invalid_endpoint' => 'Ungültige Benachrichtigungsadresse.',
        'invalid_subscription_key' => 'Ungültiger Abonnementschlüssel.',
        'invalid_key' => 'Ungültiger Signaturschlüssel.',
        'invalid_encoding' => 'Ungültige Kodierung.',
        'invalid_salt' => 'Ungültiger Verschlüsselungsparameter.',
        'key_generation_failed' => 'Schlüsselerzeugung fehlgeschlagen.',
        'signature_failed' => 'Signieren fehlgeschlagen.',
        'encryption_failed' => 'Die Benachrichtigung konnte nicht verschlüsselt werden.',
        'payload_too_large' => 'Benachrichtigung zu groß.',
        'rejected' => 'Der Benachrichtigungsdienst hat die Zustellung abgelehnt.',
        'subscription_expired' => 'Dieses Gerät ist nicht mehr angemeldet.',
        'transport' => 'Der Benachrichtigungsdienst ist nicht erreichbar.',
        'too_many_devices' => 'Zu viele angemeldete Geräte für dieses Konto.',
    ],
];
