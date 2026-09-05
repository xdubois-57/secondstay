<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SecondStay\Core\Config;
use SecondStay\Core\Container;
use SecondStay\Core\Http\Request;
use SecondStay\Core\PhpSession;
use SecondStay\Core\Services;
use SecondStay\Core\Session;

/**
 * Le cookie de session porte `Secure` quand la requête est arrivée en HTTPS.
 *
 * Ce test existe parce que la protection manquait : `Services` construisait
 * `PhpSession` avec `secure: false` **en dur**, si bien que le cookie de
 * session n'était jamais protégé, même sur une installation entièrement
 * servie en TLS. Le drapeau y était depuis toujours, et toujours à faux.
 *
 * La preuve HTTPS du scan dynamique aurait dû l'attraper et ne l'a pas fait :
 * elle acceptait n'importe quel `Set-Cookie` contenant « secure », et la
 * préférence de langue en pose un. Un garde-fou qui regarde à côté de ce
 * qu'il surveille est plus dangereux que pas de garde-fou : il rend le
 * silence rassurant.
 *
 * En clair, l'absence du drapeau est le comportement correct — le poser
 * rendrait le cookie inutilisable, donc la connexion impossible.
 */
final class SessionCookieSecureTest extends TestCase
{
    public function testTheSessionCookieIsSecureOverHttps(): void
    {
        self::assertTrue($this->secureFlagFor(['HTTPS' => 'on']));
    }

    public function testTheSessionCookieIsNotSecureInTheClear(): void
    {
        self::assertFalse($this->secureFlagFor([]));
    }

    /**
     * Un terminateur TLS devant l'application pose `X-Forwarded-Proto`. C'est
     * le cas de tout hébergement derrière un répartiteur — et précisément
     * celui où la protection compte, puisque le TLS y est réellement terminé.
     */
    public function testTheSessionCookieFollowsTheForwardedProtocol(): void
    {
        self::assertTrue($this->secureFlagFor(['HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    /**
     * Hors requête — le planificateur, une commande — il n'y a pas de
     * transport à consulter, et le service doit tout de même se construire.
     */
    public function testTheSessionStillBuildsWithoutARequest(): void
    {
        $session = $this->container(null)->get(Session::class);

        self::assertInstanceOf(PhpSession::class, $session);
        self::assertFalse($this->readSecureFlag($session));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function secureFlagFor(array $server): bool
    {
        $request = new Request('GET', '/fr/login', [], [], $server);
        $session = $this->container($request)->get(Session::class);

        self::assertInstanceOf(PhpSession::class, $session);

        return $this->readSecureFlag($session);
    }

    private function container(?Request $request): Container
    {
        $projectRoot = dirname(__DIR__, 3);

        $container = new Container();
        $container->instance(Config::class, Config::load($projectRoot));
        Services::register($container, $projectRoot, '0.0.0-test');

        if ($request !== null) {
            $container->instance(Request::class, $request);
        }

        return $container;
    }

    private function readSecureFlag(PhpSession $session): bool
    {
        $property = new ReflectionProperty(PhpSession::class, 'secure');

        return (bool) $property->getValue($session);
    }
}
