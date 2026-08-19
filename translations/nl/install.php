<?php

declare(strict_types=1);

return [
    'title' => 'SecondStay installeren',
    'intro' => 'Deze installatie beheert één woning. Vul de database, de eerste beheerder en de essentiële gegevens van de woning in.',
    'success' => 'Installatie voltooid. Welkom in het beheergedeelte.',
    'requirements_not_met' => 'Sommige verplichte vereisten zijn niet vervuld. Los ze op voordat u verdergaat.',
    'step' => [
        'requirements' => 'Serververeisten',
        'database' => 'Database',
        'administrator' => 'Eerste beheerder',
        'site' => 'Woning en taal',
    ],
    'field' => [
        'db_host' => 'Host',
        'db_port' => 'Poort',
        'db_name' => 'Databasenaam',
        'db_user' => 'Gebruiker',
        'db_password' => 'Wachtwoord',
        'db_charset' => 'Tekenset',
        'property_name' => 'Naam van de woning',
        'default_locale' => 'Standaardtaal',
        'timezone' => 'Tijdzone',
    ],
    'action' => [
        'install' => 'SecondStay installeren',
        'test_database' => 'Verbinding testen',
    ],
    'requirement' => [
        'ok' => 'OK',
        'missing' => 'Ontbreekt',
        'recommended' => 'Aanbevolen',
        'php_version' => 'PHP-versie',
        'ext_pdo_mysql' => 'PDO MySQL-extensie',
        'ext_mbstring' => 'mbstring-extensie',
        'ext_openssl' => 'OpenSSL-extensie',
        'ext_sodium' => 'libsodium-extensie',
        'ext_json' => 'JSON-extensie',
        'ext_zip' => 'ZIP-extensie',
        'ext_dom' => 'DOM-extensie',
        'ext_intl' => 'intl-extensie',
        'ext_fileinfo' => 'fileinfo-extensie',
        'ext_gd' => 'GD-extensie (afbeeldingen)',
        'ext_curl' => 'cURL-extensie',
        'ext_exif' => 'EXIF-extensie',
        'config_writable' => 'Configuratiemap is beschrijfbaar',
        'storage_writable' => 'Opslagmap is beschrijfbaar',
        'disk_space' => 'Beschikbare schijfruimte',
    ],
    'database' => [
        'connection_ok' => 'Verbinding geslaagd.',
        'error' => [
            'unknown_database' => 'De opgegeven database bestaat niet.',
            'access_denied' => 'Databasegegevens geweigerd.',
            'host_unreachable' => 'Databasehost onbereikbaar.',
            'generic' => 'Verbinding met de database is mislukt.',
        ],
    ],
    'error' => [
        'required' => 'Dit veld is verplicht.',
        'email_invalid' => 'Ongeldig e-mailadres.',
        'password_mismatch' => 'De twee wachtwoorden komen niet overeen.',
        'locale' => 'Niet-ondersteunde taal.',
        'timezone' => 'Onbekende tijdzone.',
        'generic' => 'Installatie mislukt. Raadpleeg de serverlogboeken.',
    ],
];
