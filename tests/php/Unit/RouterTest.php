<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Controller\ApiController;
use SecondStay\Controller\PageController;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Router;
use SecondStay\Core\Routes;

final class RouterTest extends TestCase
{
    public function testMatchesSimpleRoute(): void
    {
        $router = new Router();
        $router->get('/', [PageController::class, 'home'], 'home');

        $match = $router->match('GET', '/');
        self::assertSame('home', $match['name']);
        self::assertSame([PageController::class, 'home'], $match['handler']);
    }

    public function testMatchesParameters(): void
    {
        $router = new Router();
        $router->get('/reservation/{reference}', [PageController::class, 'home'], 'booking.show');

        $match = $router->match('GET', '/reservation/AB12-CD34');
        self::assertSame(['reference' => 'AB12-CD34'], $match['params']);
    }

    public function testConstrainedParameters(): void
    {
        $router = new Router();
        $router->get('/doc/{id:\d+}', [PageController::class, 'home'], 'doc.show');

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
        $router->get('/', [PageController::class, 'home'], 'home');
        self::assertSame('home', $router->match('HEAD', '/')['name']);
    }

    public function testPathGenerationAddsLocalePrefix(): void
    {
        $router = new Router();
        $router->get('/tarifs', [PageController::class, 'home'], 'rates');

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
        $router->get('/', [PageController::class, 'home'], 'home');

        self::assertSame('/fr', $router->path('home', [], 'fr'));
    }

    public function testExtraParametersBecomeQueryString(): void
    {
        $router = new Router();
        $router->get('/search', [PageController::class, 'home'], 'search');

        self::assertSame('/en/search?q=test', $router->path('search', ['q' => 'test'], 'en'));
    }

    public function testConstraintsMayContainBraceQuantifiers(): void
    {
        $router = new Router();
        $router->get(
            '/media/{variant:thumb|large|original}/{filename:[a-z0-9]+\.[a-z0-9]{2,5}}',
            [PageController::class, 'show'],
            'media.show',
            false
        );

        $match = $router->match('GET', '/media/thumb/d99dbce0ab3dcc37.png');

        self::assertSame(['variant' => 'thumb', 'filename' => 'd99dbce0ab3dcc37.png'], $match['params']);
        self::assertSame('/media/large/abc.png', $router->path('media.show', ['variant' => 'large', 'filename' => 'abc.png']));
    }

    public function testAlternationConstraintIsEnforced(): void
    {
        $router = new Router();
        $router->get('/media/{variant:thumb|large}/{file}', [PageController::class, 'show'], 'media.show', false);

        $this->expectException(NotFoundException::class);
        $router->match('GET', '/media/huge/abc.png');
    }

    public function testLiteralSegmentsAreEscaped(): void
    {
        $router = new Router();
        $router->get('/sitemap.xml', [PageController::class, 'show'], 'seo.sitemap', false);

        self::assertSame('seo.sitemap', $router->match('GET', '/sitemap.xml')['name']);

        $this->expectException(NotFoundException::class);
        $router->match('GET', '/sitemapaxml');
    }

    public function testUnclosedPlaceholderIsRejected(): void
    {
        $router = new Router();

        $this->expectException(\InvalidArgumentException::class);
        $router->get('/broken/{slug', [PageController::class, 'show'], 'broken');
    }

    public function testPlaceholderParsingHandlesNestedBraces(): void
    {
        $placeholders = Router::parsePlaceholders('/a/{id:\d{4}}/b/{slug}');

        self::assertCount(2, $placeholders);
        self::assertSame('id', $placeholders[0]['name']);
        self::assertSame('\d{4}', $placeholders[0]['constraint']);
        self::assertSame('slug', $placeholders[1]['name']);
        self::assertSame('[^/]+', $placeholders[1]['constraint']);
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
