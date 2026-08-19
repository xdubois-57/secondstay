<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Controller\ApiController;
use SecondStay\Controller\HomeController;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Router;
use SecondStay\Core\Routes;

final class RouterTest extends TestCase
{
    public function testMatchesSimpleRoute(): void
    {
        $router = new Router();
        $router->get('/', [HomeController::class, 'index'], 'home');

        $match = $router->match('GET', '/');
        self::assertSame('home', $match['name']);
        self::assertSame([HomeController::class, 'index'], $match['handler']);
    }

    public function testMatchesParameters(): void
    {
        $router = new Router();
        $router->get('/reservation/{reference}', [HomeController::class, 'index'], 'booking.show');

        $match = $router->match('GET', '/reservation/AB12-CD34');
        self::assertSame(['reference' => 'AB12-CD34'], $match['params']);
    }

    public function testConstrainedParameters(): void
    {
        $router = new Router();
        $router->get('/doc/{id:\d+}', [HomeController::class, 'index'], 'doc.show');

        $match = $router->match('GET', '/doc/42');
        self::assertSame(['id' => '42'], $match['params']);

        $this->expectException(NotFoundException::class);
        $router->match('GET', '/doc/abc');
    }

    public function testUnknownRouteThrows(): void
    {
        $router = new Router();
        $this->expectException(NotFoundException::class);
        $router->match('GET', '/nope');
    }

    public function testHeadIsTreatedAsGet(): void
    {
        $router = new Router();
        $router->get('/', [HomeController::class, 'index'], 'home');
        self::assertSame('home', $router->match('HEAD', '/')['name']);
    }

    public function testPathGenerationAddsLocalePrefix(): void
    {
        $router = new Router();
        $router->get('/tarifs', [HomeController::class, 'index'], 'rates');

        self::assertSame('/de/tarifs', $router->path('rates', [], 'de'));
        self::assertSame('/tarifs', $router->path('rates'));
    }

    public function testPathGenerationSkipsPrefixForNonLocalisedRoutes(): void
    {
        $router = new Router();
        $router->get('/api/version', [ApiController::class, 'version'], 'api.version', false);

        self::assertSame('/api/version', $router->path('api.version', [], 'nl'));
    }

    public function testPathGenerationForRoot(): void
    {
        $router = new Router();
        $router->get('/', [HomeController::class, 'index'], 'home');

        self::assertSame('/fr', $router->path('home', [], 'fr'));
    }

    public function testExtraParametersBecomeQueryString(): void
    {
        $router = new Router();
        $router->get('/search', [HomeController::class, 'index'], 'search');

        self::assertSame('/en/search?q=test', $router->path('search', ['q' => 'test'], 'en'));
    }

    public function testApplicationRoutesAreRegistered(): void
    {
        $router = new Router();
        Routes::register($router);

        self::assertTrue($router->has('home'));
        self::assertTrue($router->has('api.version'));
        self::assertTrue($router->has('api.health'));
    }
}
