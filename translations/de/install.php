<?php

declare(strict_types=1);

return [
    'title' => 'SecondStay installieren',
    'intro' => 'Diese Installation verwaltet ein einziges Objekt. Geben Sie die Datenbank, den ersten Administrator und die wichtigsten Objektdaten an.',
    'success' => 'Installation abgeschlossen. Willkommen im Verwaltungsbereich.',
    'requirements_not_met' => 'Einige Pflichtvoraussetzungen sind nicht erfüllt. Beheben Sie diese, bevor Sie fortfahren.',
    'step' => [
        'requirements' => 'Servervoraussetzungen',
        'database' => 'Datenbank',
        'administrator' => 'Erster Administrator',
        'site' => 'Objekt und Sprache',
    ],
    'field' => [
        'db_host' => 'Host',
        'db_port' => 'Port',
        'db_name' => 'Datenbankname',
        'db_user' => 'Benutzer',
        'db_password' => 'Passwort',
        'db_charset' => 'Zeichensatz',
        'property_name' => 'Name des Objekts',
        'default_locale' => 'Standardsprache',
        'timezone' => 'Zeitzone',
    ],
    'action' => [
        'install' => 'SecondStay installieren',
        'test_database' => 'Verbindung testen',
    ],
    'requirement' => [
        'ok' => 'OK',
        'missing' => 'Fehlt',
        'recommended' => 'Empfohlen',
        'php_version' => 'PHP-Version',
        'ext_pdo_mysql' => 'PDO-MySQL-Erweiterung',
        'ext_mbstring' => 'mbstring-Erweiterung',
        'ext_openssl' => 'OpenSSL-Erweiterung',
        'ext_sodium' => 'libsodium-Erweiterung',
        'ext_json' => 'JSON-Erweiterung',
        'ext_zip' => 'ZIP-Erweiterung',
        'ext_dom' => 'DOM-Erweiterung',
        'ext_intl' => 'intl-Erweiterung',
        'ext_fileinfo' => 'fileinfo-Erweiterung',
        'ext_gd' => 'GD-Erweiterung (Bilder)',
        'ext_curl' => 'cURL-Erweiterung',
        'ext_exif' => 'EXIF-Erweiterung',
        'config_writable' => 'Konfigurationsverzeichnis ist beschreibbar',
        'storage_writable' => 'Speicherverzeichnis ist beschreibbar',
        'disk_space' => 'Verfügbarer Speicherplatz',
    ],
    'database' => [
        'connection_ok' => 'Verbindung erfolgreich.',
        'error' => [
            'unknown_database' => 'Die angegebene Datenbank existiert nicht.',
            'access_denied' => 'Datenbank-Zugangsdaten abgelehnt.',
            'host_unreachable' => 'Datenbank-Host nicht erreichbar.',
            'generic' => 'Verbindung zur Datenbank nicht möglich.',
        ],
    ],
    'error' => [
        'required' => 'Dieses Feld ist erforderlich.',
        'email_invalid' => 'Ungültige E-Mail-Adresse.',
        'password_mismatch' => 'Die beiden Passwörter stimmen nicht überein.',
        'locale' => 'Nicht unterstützte Sprache.',
        'timezone' => 'Unbekannte Zeitzone.',
        'generic' => 'Installation fehlgeschlagen. Prüfen Sie die Serverprotokolle.',
    ],
];
