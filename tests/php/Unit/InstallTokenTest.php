<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Core\Http\Request;
use SecondStay\Core\Session;
use SecondStay\Installer\InstallToken;
use SecondStay\Installer\InstallTokenGate;
use SecondStay\Installer\InstallTokenVerdict;

/**
 * Portail par jeton devant l'assistant d'installation.
 *
 * Ce portail existe parce qu'une instance neuve a une fenêtre pendant laquelle
 * l'assistant crée le premier administrateur sans qu'aucune authentification
 * ne soit possible — par construction, puisqu'il n'y a encore personne. Sur un
 * hébergement public, cette fenêtre appartient à qui arrive le premier :
 * celui qui charge `/install` avant le propriétaire choisit la base, le mot de
 * passe administrateur, et devient l'exploitant du site.
 */
final class InstallTokenTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/secondstay-token-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/token.php')) {
            unlink($this->root . '/token.php');
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    // -------------------------------------------------------------------
    // Lecture du fichier
    // -------------------------------------------------------------------

    public function testAnAbsentFileMeansNoProtectionIsConfigured(): void
    {
        self::assertFalse($this->token()->isConfigured());
        self::assertFalse($this->token()->matches(str_repeat('a', 64)));
    }

    public function testAValidFileYieldsAMatchingToken(): void
    {
        $secret = $this->write();
        $token = $this->token();

        self::assertTrue($token->isConfigured());
        self::assertTrue($token->matches($secret));
        self::assertFalse($token->matches(str_repeat('b', 64)));
        self::assertFalse($token->matches(''));
    }

    /**
     * Le fichier est lu comme du texte, jamais inclus : son contenu vient du
     * disque d'un hébergement dont l'application ne sait rien, et l'exécuter
     * reviendrait à faire tourner ce que le premier fichier déposé à la racine
     * contient. Ce test le vérifie par l'effet : un `token.php` qui définirait
     * une constante à l'inclusion n'en définit aucune.
     */
    public function testTheFileIsReadAsTextAndNeverIncluded(): void
    {
        file_put_contents(
            $this->root . '/token.php',
            "<?php define('SECONDSTAY_TOKEN_FILE_WAS_INCLUDED', true);\n"
            . '// ' . InstallToken::MARKER . ': ' . str_repeat('c', 64) . "\n"
        );

        self::assertTrue($this->token()->matches(str_repeat('c', 64)));
        self::assertFalse(defined('SECONDSTAY_TOKEN_FILE_WAS_INCLUDED'));
    }

    /**
     * Un fichier présent mais sans marqueur exploitable compte comme absence
     * de protection. Le contraire enfermerait dehors le propriétaire d'un
     * fichier corrompu, sans rien apprendre à personne d'autre.
     */
    public function testAFileWithoutAUsableMarkerCountsAsNoProtection(): void
    {
        file_put_contents($this->root . '/token.php', "<?php\n// rien d'utile ici\n");

        self::assertFalse($this->token()->isConfigured());
    }

    /**
     * Un marqueur tronqué ou en dehors de l'alphabet attendu n'est pas un
     * jeton : accepter une valeur plus courte reviendrait à accepter un
     * secret plus faible que celui qui a été généré.
     */
    public function testATruncatedOrMalformedMarkerIsNotAToken(): void
    {
        file_put_contents(
            $this->root . '/token.php',
            "<?php\n// " . InstallToken::MARKER . ': ' . str_repeat('d', 32) . "\n"
        );
        self::assertFalse($this->token()->isConfigured());

        file_put_contents(
            $this->root . '/token.php',
            "<?php\n// " . InstallToken::MARKER . ': ' . str_repeat('Z', 64) . "\n"
        );
        self::assertFalse($this->token()->isConfigured());
    }

    public function testDeletingTheTokenRemovesTheProtection(): void
    {
        $this->write();
        $token = $this->token();
        self::assertTrue($token->isConfigured());

        $token->delete();

        self::assertFileDoesNotExist($this->root . '/token.php');
        self::assertFalse($token->isConfigured());
    }

    public function testDeletingAnAbsentTokenIsHarmless(): void
    {
        $this->token()->delete();

        self::assertFileDoesNotExist($this->root . '/token.php');
    }

    // -------------------------------------------------------------------
    // Portail
    // -------------------------------------------------------------------

    /**
     * Sans `token.php`, l'assistant reste ouvert. Une installation faite à la
     * main — clone du dépôt, développement, campagne de tests — n'a jamais eu
     * de jeton à présenter, et refuser l'accès dans ce cas transformerait
     * l'absence d'un fichier en verrou définitif.
     */
    public function testWithoutATokenFileTheWizardStaysOpen(): void
    {
        self::assertSame(
            InstallTokenVerdict::Allowed,
            $this->gate(new Session())->authorise($this->request())
        );
    }

    public function testAValidTokenIsAcceptedThenRememberedForTheSession(): void
    {
        $secret = $this->write();
        $session = new Session();
        $gate = $this->gate($session);

        self::assertSame(
            InstallTokenVerdict::Accepted,
            $gate->authorise($this->request(['jeton' => $secret]))
        );
        self::assertTrue($session->get(InstallTokenGate::SESSION_VERIFIED_KEY));

        // Les visites suivantes n'ont plus besoin du jeton.
        self::assertSame(InstallTokenVerdict::Allowed, $gate->authorise($this->request()));
    }

    public function testAWrongTokenIsRefused(): void
    {
        $this->write();

        self::assertSame(
            InstallTokenVerdict::Denied,
            $this->gate(new Session())->authorise($this->request(['jeton' => str_repeat('e', 64)]))
        );
    }

    public function testNoTokenAtAllIsRefusedWhenOneIsRequired(): void
    {
        $this->write();

        self::assertSame(
            InstallTokenVerdict::Denied,
            $this->gate(new Session())->authorise($this->request())
        );
    }

    /**
     * Le jeton ne reste pas dans l'URL : il finirait dans l'historique du
     * navigateur, dans le `Referer` de chaque ressource externe et dans les
     * journaux d'accès de l'hébergeur. Le reste de la requête, lui, survit.
     */
    public function testTheRedirectionStripsTheTokenAndKeepsEverythingElse(): void
    {
        $gate = $this->gate(new Session());

        self::assertSame(
            '/fr/install',
            $gate->cleanUrl($this->request(['jeton' => str_repeat('f', 64)]))
        );
        self::assertSame(
            '/fr/install?etape=2',
            $gate->cleanUrl($this->request(['jeton' => str_repeat('f', 64), 'etape' => '2']))
        );
    }

    public function testTheRedirectionKeepsTheBasePathOfASubdirectoryInstall(): void
    {
        $gate = $this->gate(new Session());
        $request = new Request('GET', '/fr/install', ['jeton' => str_repeat('f', 64)], [], [], [], [], '', '/site');

        self::assertSame('/site/fr/install', $gate->cleanUrl($request));
    }

    /**
     * Une visite sans jeton ne consomme pas d'essai : la toute première
     * ouverture de l'assistant se fait sans, et la compter épuiserait le
     * budget avant que l'opérateur n'ait rien tenté.
     */
    public function testAVisitWithoutATokenDoesNotConsumeAnAttempt(): void
    {
        $secret = $this->write();
        $session = new Session();
        $gate = $this->gate($session);

        for ($visit = 0; $visit < 20; $visit++) {
            self::assertSame(InstallTokenVerdict::Denied, $gate->authorise($this->request()));
        }

        self::assertSame(InstallTokenVerdict::Accepted, $gate->authorise($this->request(['jeton' => $secret])));
    }

    /**
     * Passé le seuil, l'accès est refusé même avec le bon jeton, et le refus
     * dure. Ce compteur n'est pas là pour arrêter une force brute — 256 bits
     * s'en chargent — mais pour qu'une tentative laisse une trace et coûte
     * quelque chose.
     */
    public function testTooManyWrongTokensLockTheSessionOutEvenWithTheRightOne(): void
    {
        $secret = $this->write();
        $session = new Session();
        $clock = 1_000_000;
        $gate = $this->gate($session, static function () use (&$clock): int {
            return $clock;
        });

        for ($attempt = 0; $attempt < 5; $attempt++) {
            self::assertSame(
                InstallTokenVerdict::Denied,
                $gate->authorise($this->request(['jeton' => str_repeat('0', 64)]))
            );
        }

        self::assertSame(InstallTokenVerdict::Denied, $gate->authorise($this->request(['jeton' => $secret])));

        // Le verrou se lève de lui-même : un opérateur qui s'est trompé ne
        // reste pas dehors indéfiniment.
        $clock += 901;
        self::assertSame(InstallTokenVerdict::Accepted, $gate->authorise($this->request(['jeton' => $secret])));
    }

    /**
     * Quatre erreurs ne verrouillent pas, **et** la réussite qui suit remet le
     * compteur à zéro.
     *
     * La seconde moitié est ce que ce test ne vérifiait pas : il s'arrêtait à
     * la réussite, qui n'apprend rien du compteur — quatre échecs sont sous le
     * seuil de cinq, donc le jeton juste passait de toute façon. Le test
     * portait donc un nom que rien ne soutenait, et le jour où la remise à
     * zéro disparaîtrait, il resterait vert.
     */
    public function testASuccessBeforeTheThresholdClearsTheCounter(): void
    {
        $secret = $this->write();
        $session = new Session();
        $gate = $this->gate($session);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            self::assertSame(
                InstallTokenVerdict::Denied,
                $gate->authorise($this->request(['jeton' => str_repeat('0', 64)]))
            );
        }

        self::assertSame(InstallTokenVerdict::Accepted, $gate->authorise($this->request(['jeton' => $secret])));

        // Et la remise à zéro elle-même, constatée là où elle a lieu.
        //
        // Elle ne se voit pas par un verdict : une fois la session marquée
        // vérifiée, `authorise()` répond `Allowed` sans jamais redescendre au
        // compteur. C'est de la défense en profondeur — aucun chemin actuel
        // n'en dépend — et c'est justement pour cela qu'elle a besoin d'un
        // test : rien d'autre ne dirait qu'elle a disparu. La clé est lue
        // telle qu'elle est écrite en session, parce que ce qui est vérifié
        // ici est précisément l'état persisté.
        self::assertNull($session->int('_install_token_failures'));
        self::assertTrue($session->get(InstallTokenGate::SESSION_VERIFIED_KEY));
    }

    // -------------------------------------------------------------------

    private function token(): InstallToken
    {
        return InstallToken::forRoot($this->root);
    }

    /**
     * @param (callable(): int)|null $now
     */
    private function gate(Session $session, ?callable $now = null): InstallTokenGate
    {
        return new InstallTokenGate($this->token(), $session, $now);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query = []): Request
    {
        return new Request('GET', '/fr/install', $query);
    }

    /**
     * Écrit un `token.php` de la forme exacte que produit `bootstrap.php`.
     */
    private function write(): string
    {
        $secret = bin2hex(random_bytes(32));
        file_put_contents(
            $this->root . '/token.php',
            "<?php\n\ndeclare(strict_types=1);\n\nhttp_response_code(404);\nexit;\n\n"
            . '// ' . InstallToken::MARKER . ': ' . $secret . "\n"
        );

        return $secret;
    }
}
