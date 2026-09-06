<?php

declare(strict_types=1);

return [
    'title' => 'Installation de SecondStay',
    'intro' => 'Cette installation gère un seul logement. Renseignez la base de données, le premier administrateur et '
        . 'les informations essentielles du logement.',
    'success' => 'Installation terminée. Bienvenue dans l’administration.',
    'requirements_not_met' => 'Certains prérequis obligatoires ne sont pas satisfaits. Corrigez-les avant de '
        . 'poursuivre.',
    'step' => [
        'requirements' => 'Prérequis serveur',
        'database' => 'Base de données',
        'administrator' => 'Premier administrateur',
        'site' => 'Logement et langue',
    ],
    'field' => [
        'db_host' => 'Hôte',
        'db_port' => 'Port',
        'db_name' => 'Nom de la base',
        'db_user' => 'Utilisateur',
        'db_password' => 'Mot de passe',
        'db_charset' => 'Jeu de caractères',
        'property_name' => 'Nom du logement',
        'default_locale' => 'Langue par défaut',
        'timezone' => 'Fuseau horaire',
    ],
    'action' => [
        'install' => 'Installer SecondStay',
        'test_database' => 'Tester la connexion',
    ],
    'requirement' => [
        'ok' => 'OK',
        'missing' => 'Manquant',
        'recommended' => 'Recommandé',
        'php_version' => 'Version de PHP',
        'ext_pdo_mysql' => 'Extension PDO MySQL',
        'ext_mbstring' => 'Extension mbstring',
        'ext_openssl' => 'Extension OpenSSL',
        'ext_sodium' => 'Extension libsodium',
        'ext_json' => 'Extension JSON',
        'ext_zip' => 'Extension ZIP',
        'ext_dom' => 'Extension DOM',
        'ext_intl' => 'Extension intl',
        'ext_fileinfo' => 'Extension fileinfo',
        'ext_gd' => 'Extension GD (images)',
        'ext_curl' => 'Extension cURL',
        'ext_exif' => 'Extension EXIF',
        'config_writable' => 'Répertoire de configuration accessible en écriture',
        'storage_writable' => 'Répertoire de stockage accessible en écriture',
        'disk_space' => 'Espace disque disponible',
    ],
    'database' => [
        'connection_ok' => 'Connexion réussie.',
        'error' => [
            'unknown_database' => 'La base de données indiquée n’existe pas.',
            'access_denied' => 'Identifiants de base de données refusés.',
            'host_unreachable' => 'Hôte de base de données injoignable.',
            'generic' => 'Connexion à la base de données impossible.',
        ],
    ],
    'error' => [
        'required' => 'Ce champ est obligatoire.',
        'email_invalid' => 'Adresse e-mail invalide.',
        'password_mismatch' => 'Les deux mots de passe ne correspondent pas.',
        'locale' => 'Langue non supportée.',
        'timezone' => 'Fuseau horaire inconnu.',
        'generic' => 'L’installation a échoué. Consultez les journaux du serveur.',
    ],
];
