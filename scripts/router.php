<?php

declare(strict_types=1);

/**
 * Routeur du serveur PHP intégré (développement, tests E2E).
 *
 * Il applique la même politique de chemins privés que le `.htaccess` racine
 * afin que les tests de sécurité soient représentatifs.
 *
 * Usage : php -S 127.0.0.1:8080 -t . scripts/router.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SecondStay\Core\PublicPathPolicy;

$root = dirname(__DIR__);
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = rawurldecode($uri);

if (PublicPathPolicy::isBlocked($path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "403 Forbidden\n";

    return true;
}

$candidate = realpath($root . '/public' . $path);
$publicRoot = realpath($root . '/public');

if ($candidate !== false && $publicRoot !== false && is_file($candidate) && str_starts_with($candidate, $publicRoot)) {
    if (str_ends_with($candidate, '.php') && !str_ends_with($candidate, '/public/index.php')) {
        http_response_code(403);

        return true;
    }

    if (!str_ends_with($candidate, '.php')) {
        $extension = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        $types = [
            'css' => 'text/css; charset=UTF-8',
            'js' => 'text/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'webmanifest' => 'application/manifest+json; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            'txt' => 'text/plain; charset=UTF-8',
            'map' => 'application/json; charset=UTF-8',
        ];

        header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=60');
        readfile($candidate);

        return true;
    }
}

$_SERVER['SCRIPT_NAME'] = '/public/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';

require $root . '/public/index.php';

return true;
