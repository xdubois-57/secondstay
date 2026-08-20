<?php

declare(strict_types=1);

/**
 * Configuration par defaut de SecondStay.
 *
 * Ce fichier ne contient JAMAIS de secret. Les valeurs specifiques a une
 * installation vivent dans `config/local.php` (non versionne) ou en base.
 */

return [
    'app' => [
        'name' => 'SecondStay',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Europe/Paris',
        'currency' => 'EUR',
    ],
    'i18n' => [
        'default_locale' => 'fr',
        'locales' => ['fr', 'en', 'nl', 'de'],
        'fallback_locale' => 'fr',
        'cookie_name' => 'ss_locale',
        'cookie_lifetime_days' => 365,
    ],
    'database' => [
        'dsn' => '',
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'encryption_key' => '',
        'session_name' => 'secondstay_session',
        'session_lifetime_minutes' => 120,
        'csrf_field' => '_csrf',
    ],
    'paths' => [
        'storage' => '',
    ],
    'mail' => [
        // 'smtp' en production. 'fake' n'est activable que par la variable
        // d'environnement SECONDSTAY_MAIL_TRANSPORT, pour les tests.
        'transport' => 'smtp',
    ],
    'push' => [
        // 'webpush' en production. 'fake' n'est activable que par la variable
        // d'environnement SECONDSTAY_PUSH_PROVIDER, pour les tests.
        'provider' => 'webpush',
    ],
    'payment' => [
        // Le fournisseur réel est choisi dans la configuration de
        // l'installation. 'fake' n'est activable que par la variable
        // d'environnement SECONDSTAY_PAYMENT_PROVIDER, pour les tests : sans
        // cela, un visiteur pourrait confirmer un séjour sans jamais payer.
        'provider' => '',
    ],
    'imap' => [
        // Boîte réelle configurée dans l'installation. 'fake' n'est activable
        // que par la variable d'environnement SECONDSTAY_IMAP_PROVIDER, pour
        // les tests : une boîte factice ne doit jamais servir en production.
        'provider' => '',
    ],
    'logging' => [
        'level' => 'info',
        'retention_days' => 90,
    ],
];
