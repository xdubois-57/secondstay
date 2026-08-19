<?php

declare(strict_types=1);

/**
 * SecondStay — front controller unique.
 *
 * Toutes les requêtes publiques passent par ce fichier. Aucun autre fichier PHP
 * de l'application ne doit être atteignable directement (voir SECURITY.md).
 */

use SecondStay\Core\Http\Request;
use SecondStay\Core\Kernel;

$projectRoot = dirname(__DIR__);

$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "SecondStay: dependencies are missing (vendor/autoload.php).\n";
    exit(1);
}

require $autoload;

$kernel = new Kernel($projectRoot);
$kernel->handle(Request::fromGlobals())->send();
