<?php

declare(strict_types=1);

return [
    'title' => 'SecondStay installation',
    'intro' => 'This installation manages a single property. Provide the database, the first administrator and the essential property information.',
    'success' => 'Installation complete. Welcome to the administration area.',
    'requirements_not_met' => 'Some mandatory requirements are not met. Fix them before continuing.',
    'step' => [
        'requirements' => 'Server requirements',
        'database' => 'Database',
        'administrator' => 'First administrator',
        'site' => 'Property and language',
    ],
    'field' => [
        'db_host' => 'Host',
        'db_port' => 'Port',
        'db_name' => 'Database name',
        'db_user' => 'User',
        'db_password' => 'Password',
        'db_charset' => 'Character set',
        'property_name' => 'Property name',
        'default_locale' => 'Default language',
        'timezone' => 'Time zone',
    ],
    'action' => [
        'install' => 'Install SecondStay',
        'test_database' => 'Test connection',
    ],
    'requirement' => [
        'ok' => 'OK',
        'missing' => 'Missing',
        'recommended' => 'Recommended',
        'php_version' => 'PHP version',
        'ext_pdo_mysql' => 'PDO MySQL extension',
        'ext_mbstring' => 'mbstring extension',
        'ext_openssl' => 'OpenSSL extension',
        'ext_sodium' => 'libsodium extension',
        'ext_json' => 'JSON extension',
        'ext_zip' => 'ZIP extension',
        'ext_dom' => 'DOM extension',
        'ext_intl' => 'intl extension',
        'ext_fileinfo' => 'fileinfo extension',
        'ext_gd' => 'GD extension (images)',
        'ext_curl' => 'cURL extension',
        'ext_exif' => 'EXIF extension',
        'config_writable' => 'Configuration directory is writable',
        'storage_writable' => 'Storage directory is writable',
        'disk_space' => 'Available disk space',
    ],
    'database' => [
        'connection_ok' => 'Connection successful.',
        'error' => [
            'unknown_database' => 'The specified database does not exist.',
            'access_denied' => 'Database credentials were refused.',
            'host_unreachable' => 'Database host unreachable.',
            'generic' => 'Could not connect to the database.',
        ],
    ],
    'error' => [
        'required' => 'This field is required.',
        'email_invalid' => 'Invalid email address.',
        'password_mismatch' => 'The two passwords do not match.',
        'locale' => 'Unsupported language.',
        'timezone' => 'Unknown time zone.',
        'generic' => 'Installation failed. Check the server logs.',
    ],
];
