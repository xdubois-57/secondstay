<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Controller\ApiController;
use SecondStay\Controller\HomeController;

/**
 * Table de routage de l'application.
 *
 * Les routes « localised » acceptent un préfixe de langue (/fr, /en, /nl, /de).
 */
final class Routes
{
    public static function register(Router $router): void
    {
        $router->get('/', [HomeController::class, 'index'], 'home');

        $router->get('/api/version', [ApiController::class, 'version'], 'api.version', false);
        $router->get('/api/health', [ApiController::class, 'health'], 'api.health', false);
    }
}
