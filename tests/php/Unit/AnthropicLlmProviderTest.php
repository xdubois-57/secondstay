<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Http\FakeHttpFetcher;
use SecondStay\Http\UrlGuard;
use SecondStay\Llm\AnthropicLlmProvider;
use SecondStay\Llm\LlmPrompt;
use SecondStay\Logging\Logger;

/**
 * Frontière avec le modèle (SPECIFICATIONS.md §56 et §59).
 *
 * Ce qui est vérifié ici, ce n'est pas que l'appel « marche » quand tout va
 * bien — c'est ce qui se passe quand tout va mal, parce que c'est là que le
 * produit doit rester honnête : une réponse tronquée, un refus de sécurité, un
 * corps qui n'est pas du JSON, un réseau absent. Aucun de ces cas ne doit
 * produire une activité inventée dans le livret d'un voyageur.
 *
 * Et dans tous les cas : ni la clé d'API, ni le texte du prompt ne doivent
 * atteindre le journal.
 */
final class AnthropicLlmProviderTest extends TestCase
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private string $logDirectory;

    protected function setUp(): void
    {
        $this->logDirectory = sys_get_temp_dir() . '/secondstay-llm-' . bin2hex(random_bytes(6));
        mkdir($this->logDirectory, 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->logDirectory);
    }

    public function testWithoutAKeyNothingIsEvenAttempted(): void
    {
        $http = $this->fetcher();
        $result = $this->provider($http, '')->complete($this->prompt());

        self::assertFalse($result->ok);
        self::assertSame('llm.error.not_configured', $result->error);
        self::assertSame([], $http->postedRequests, 'Aucun appel ne doit partir sans clé.');
    }

    public function testAValidAnswerIsDecodedWithItsUsage(): void
    {
        $http = $this->fetcher();
        $http->addResponse(self::ENDPOINT, (string) json_encode([
            'model' => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '{"activities":[{"title":"Marché"}]}']],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
        ]));

        $result = $this->provider($http)->complete($this->prompt());

        self::assertTrue($result->ok);
        self::assertSame([['title' => 'Marché']], $result->data['activities'] ?? null);
        self::assertSame(120, $result->inputTokens);
        self::assertSame(45, $result->outputTokens);
        self::assertSame('claude-opus-5', $result->model);
    }

    /**
     * La clé part dans l'en-tête, jamais dans l'URL ni dans le corps : une URL
     * se retrouve dans les journaux d'un proxy, un corps dans un rapport
     * d'erreur.
     */
    public function testTheKeyTravelsInTheHeaderAndNowhereElse(): void
    {
        $http = $this->fetcher();
        $http->addResponse(self::ENDPOINT, (string) json_encode([
            'content' => [['type' => 'text', 'text' => '{}']],
        ]));

        $this->provider($http, 'sk-ant-secret-de-test')->complete($this->prompt());

        self::assertCount(1, $http->postedRequests);
        $request = $http->postedRequests[0];

        self::assertSame('sk-ant-secret-de-test', $request['headers']['x-api-key'] ?? null);
        self::assertStringNotContainsString('sk-ant-secret-de-test', $request['url']);
        self::assertStringNotContainsString('sk-ant-secret-de-test', $request['body']);
    }

    /**
     * Un refus de sécurité est une réponse valide côté HTTP : le confondre
     * avec une panne ferait réessayer indéfiniment.
     */
    public function testARefusalIsRecognisedAndNotTreatedAsAnOutage(): void
    {
        $http = $this->fetcher();
        $http->addResponse(self::ENDPOINT, (string) json_encode([
            'stop_reason' => 'refusal',
            'content' => [['type' => 'text', 'text' => '{"activities":[]}']],
        ]));

        $result = $this->provider($http)->complete($this->prompt());

        self::assertFalse($result->ok);
        self::assertSame('llm.error.refused', $result->error);
    }

    public function testAnHttpErrorCarriesItsStatusWithoutInventingContent(): void
    {
        $http = $this->fetcher();
        $http->addResponse(self::ENDPOINT, '{"error":"overloaded"}', 529);

        $result = $this->provider($http)->complete($this->prompt());

        self::assertFalse($result->ok);
        self::assertSame('llm.error.status_529', $result->error);
        self::assertSame([], $result->data);
    }

    public function testABodyThatIsNotJsonIsRejected(): void
    {
        $http = $this->fetcher();
        $http->addResponse(self::ENDPOINT, '<html>maintenance</html>');

        self::assertSame('llm.error.malformed', $this->provider($http)->complete($this->prompt())->error);
    }

    /**
     * Une enveloppe correcte dont le bloc de texte ne contient pas de JSON :
     * le modèle a répondu en prose là où un schéma était demandé.
     */
    public function testProseWhereASchemaWasExpectedIsRejected(): void
    {
        $http = $this->fetcher();
        $http->addResponse(self::ENDPOINT, (string) json_encode([
            'content' => [['type' => 'text', 'text' => 'Voici quelques idées de sorties.']],
        ]));

        self::assertSame('llm.error.malformed', $this->provider($http)->complete($this->prompt())->error);
    }

    public function testAnEnvelopeWithoutAnyTextBlockIsRejected(): void
    {
        $http = $this->fetcher();
        $http->addResponse(self::ENDPOINT, (string) json_encode([
            'content' => [['type' => 'thinking', 'thinking' => '…']],
        ]));

        self::assertSame('llm.error.empty', $this->provider($http)->complete($this->prompt())->error);
    }

    /**
     * L'hôte du modèle est joignable par le garde SSRF, mais le réseau peut
     * refuser : le produit doit le dire, sans faire tomber la génération.
     */
    public function testAnUnreachableEndpointIsReportedAsSuch(): void
    {
        // Aucun hôte ne résout : le garde refuse la sortie, ce qui lève.
        $blocked = new FakeHttpFetcher(new UrlGuard([], static fn (string $host): array => []));

        $result = $this->provider($blocked)->complete($this->prompt());

        self::assertFalse($result->ok);
        self::assertSame('llm.error.unreachable', $result->error);
    }

    /**
     * SECURITY.md — ni la clé, ni le prompt ne doivent apparaître dans le
     * journal, y compris sur le chemin d'erreur où l'on est le plus tenté
     * d'écrire « tout » pour comprendre.
     */
    public function testNeitherTheKeyNorThePromptEverReachesTheLog(): void
    {
        $blocked = new FakeHttpFetcher(new UrlGuard([], static fn (string $host): array => []));

        $this->provider($blocked, 'sk-ant-secret-de-test')->complete(new LlmPrompt(
            'Consigne système confidentielle',
            'Le voyageur s’appelle Claire Dubois et loge rue des Pins.',
            ['type' => 'object'],
        ));

        $written = '';
        foreach (glob($this->logDirectory . '/*') ?: [] as $file) {
            $written .= (string) file_get_contents($file);
        }

        self::assertStringNotContainsString('sk-ant-secret-de-test', $written);
        self::assertStringNotContainsString('Claire Dubois', $written);
        self::assertStringNotContainsString('rue des Pins', $written);
        self::assertStringNotContainsString('Consigne système confidentielle', $written);
    }

    public function testTheProviderNamesItselfAndReportsItsConfiguration(): void
    {
        $http = $this->fetcher();

        self::assertSame('anthropic', $this->provider($http)->name());
        self::assertTrue($this->provider($http)->isConfigured());
        self::assertFalse($this->provider($http, '   ')->isConfigured());
    }

    private function fetcher(): FakeHttpFetcher
    {
        // L'hôte du modèle est résolu vers une adresse publique : le garde
        // SSRF du produit reste actif, seule la résolution est injectée.
        return new FakeHttpFetcher(new UrlGuard([], static function (string $host): array {
            return $host === 'api.anthropic.com' ? ['160.79.104.10'] : [];
        }));
    }

    private function provider(FakeHttpFetcher $http, string $key = 'sk-ant-de-test'): AnthropicLlmProvider
    {
        return new AnthropicLlmProvider($http, new Logger($this->logDirectory), $key);
    }

    private function prompt(): LlmPrompt
    {
        return new LlmPrompt('Système', 'Utilisateur', ['type' => 'object']);
    }
}
