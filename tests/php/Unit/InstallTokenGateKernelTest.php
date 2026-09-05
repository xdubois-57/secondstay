<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use SecondStay\Core\Http\Request;
use SecondStay\Core\Http\Response;
use SecondStay\Installer\InstallToken;
use SecondStay\Tests\Support\KernelTestCase;

/**
 * Le portail par jeton, câblé dans le noyau.
 *
 * `InstallTokenTest` couvre la décision ; ce test-ci couvre le câblage, parce
 * qu'une protection correcte mais jamais consultée protège exactement autant
 * qu'aucune protection. Il décrit l'installation telle que
 * `bootstrap/bootstrap.php` la laisse : `token.php` posé à la racine, aucune
 * configuration locale, aucun administrateur.
 */
final class InstallTokenGateKernelTest extends KernelTestCase
{
    /**
     * Sans `token.php`, l'assistant reste ouvert : c'est le cas de toutes les
     * installations manuelles, et de la campagne E2E elle-même.
     */
    public function testTheWizardStaysOpenWhenNoTokenWasEverWritten(): void
    {
        self::assertSame(200, $this->get('/fr/install')->status());
    }

    public function testTheWizardIsForbiddenWithoutTheToken(): void
    {
        $this->writeToken();

        self::assertSame(403, $this->get('/fr/install')->status());
    }

    public function testEveryRouteOfTheWizardIsBehindTheGate(): void
    {
        $this->writeToken();

        $response = $this->kernel()->handle(
            new Request('POST', '/fr/install/test-database', [], ['db_host' => '127.0.0.1'])
        );

        self::assertSame(403, $response->status());
    }

    /**
     * Le jeton présenté une fois est mémorisé, puis retiré de l'URL : une URL
     * finit dans l'historique du navigateur et dans les journaux d'accès.
     */
    public function testTheTokenIsAcceptedOnceThenStrippedFromTheUrl(): void
    {
        $secret = $this->writeToken();

        $accepted = $this->getWithToken($secret);
        self::assertSame(302, $accepted->status());
        self::assertSame('/fr/install', $accepted->headers()['location'] ?? null);

        $followed = $this->get('/fr/install');
        self::assertSame(200, $followed->status());
        self::assertStringContainsString('data-testid="install-form"', $followed->content());
    }

    public function testAWrongTokenChangesNothing(): void
    {
        $this->writeToken();

        self::assertSame(403, $this->getWithToken(str_repeat('a', 64))->status());
        self::assertSame(403, $this->get('/fr/install')->status());
    }

    /**
     * Le portail ne s'applique qu'à l'assistant : une instance non installée
     * doit continuer d'y renvoyer le visiteur, sans quoi le propriétaire
     * verrait un 403 sans savoir où aller.
     */
    public function testTheRestOfTheSiteStillPointsAtTheWizard(): void
    {
        $this->writeToken();

        $response = $this->get('/fr/');

        self::assertSame(302, $response->status());
        self::assertSame('/fr/install', $response->headers()['location'] ?? null);
    }

    private function getWithToken(string $token): Response
    {
        return $this->kernel()->handle(
            new Request('GET', '/fr/install', [InstallToken::REQUEST_PARAMETER => $token])
        );
    }

    /**
     * Écrit un `token.php` de la forme exacte que produit `bootstrap.php`.
     */
    private function writeToken(): string
    {
        $secret = bin2hex(random_bytes(32));
        file_put_contents(
            $this->projectRoot() . '/' . InstallToken::FILE_NAME,
            "<?php\n\ndeclare(strict_types=1);\n\nhttp_response_code(404);\nexit;\n\n"
            . '// ' . InstallToken::MARKER . ': ' . $secret . "\n"
        );

        return $secret;
    }
}
