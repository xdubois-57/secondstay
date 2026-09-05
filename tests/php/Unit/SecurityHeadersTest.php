<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use SecondStay\Tests\Support\KernelTestCase;

/**
 * Les en-têtes de sécurité, et notamment celui qui dépend du transport.
 *
 * `Strict-Transport-Security` n'a de sens qu'en HTTPS. L'émettre en clair ne
 * protégerait rien — un attaquant capable de modifier la réponse peut aussi
 * retirer l'en-tête — et l'émettre depuis une installation qui n'a pas de TLS
 * rendrait le site injoignable pour la durée annoncée.
 *
 * Ce test existe parce que l'en-tête manquait : la campagne de bout en bout
 * tourne en clair, où son absence est le comportement correct, et rien
 * n'exerçait donc le cas HTTPS.
 */
final class SecurityHeadersTest extends KernelTestCase
{
    public function testHstsIsSentWhenTheRequestArrivedOverHttps(): void
    {
        $response = $this->get('/fr/', ['HTTPS' => 'on']);

        self::assertSame('max-age=15552000', $response->headers()['strict-transport-security'] ?? null);
    }

    public function testHstsIsNotSentInTheClear(): void
    {
        $response = $this->get('/fr/');

        self::assertArrayNotHasKey('strict-transport-security', $response->headers());
    }

    /**
     * Un terminateur TLS devant l'application pose `X-Forwarded-Proto`, ce que
     * `Request::isSecure()` honore : c'est le cas de tout hébergement derrière
     * un répartiteur, et celui du harnais de scan dynamique.
     */
    public function testHstsFollowsTheForwardedProtocol(): void
    {
        $response = $this->get('/fr/', ['HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertArrayHasKey('strict-transport-security', $response->headers());
    }

    public function testTheTransportIndependentHeadersAreAlwaysPresent(): void
    {
        $headers = $this->get('/fr/')->headers();

        self::assertSame('nosniff', $headers['x-content-type-options'] ?? null);
        self::assertSame('SAMEORIGIN', $headers['x-frame-options'] ?? null);
        self::assertSame('strict-origin-when-cross-origin', $headers['referrer-policy'] ?? null);
        self::assertStringContainsString(
            "default-src 'self'",
            (string) ($headers['content-security-policy'] ?? '')
        );
    }
}
