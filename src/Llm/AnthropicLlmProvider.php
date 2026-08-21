<?php

declare(strict_types=1);

namespace SecondStay\Llm;

use SecondStay\Http\HttpFetcher;
use SecondStay\Logging\Logger;
use Throwable;

/**
 * Fournisseur Claude, appelé en HTTP direct sur l'API Messages.
 *
 * Pourquoi pas le SDK officiel ? Deux raisons propres à ce produit :
 *
 * 1. **une seule sortie réseau**. Tout ce qui sort passe par `HttpFetcher`,
 *    donc par le garde SSRF (ARCHITECTURE.md §3, SECURITY.md §16). Un client
 *    HTTP embarqué par une bibliothèque contournerait ce point de passage
 *    unique, qui est précisément ce que la spécification demande de tenir ;
 * 2. **hébergement mutualisé**. Le ZIP de release embarque `vendor/` et
 *    s'installe par FTP, sans Composer : chaque dépendance ajoutée est du
 *    poids transféré à chaque mise à jour.
 *
 * La forme de la requête suit la documentation de l'API Messages : version
 * d'API dans l'en-tête, sortie contrainte par `output_config.format`.
 */
final class AnthropicLlmProvider implements LlmProvider
{
    public const NAME = 'anthropic';

    /** Point d'entrée de l'API Messages. */
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** Version d'API, envoyée à chaque appel. */
    private const API_VERSION = '2023-06-01';

    /** Modèle retenu par défaut. */
    public const DEFAULT_MODEL = 'claude-opus-5';

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly Logger $logger,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function complete(LlmPrompt $prompt): LlmResult
    {
        if (!$this->isConfigured()) {
            return LlmResult::failure('llm.error.not_configured');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $prompt->maxTokens,
            'system' => $prompt->system,
            'messages' => [
                ['role' => 'user', 'content' => $prompt->user],
            ],
            // La sortie est contrainte par le schéma : le modèle ne peut pas
            // répondre en prose là où l'on attend des activités.
            'output_config' => [
                'effort' => $prompt->effort,
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $prompt->schema,
                ],
            ],
        ];

        try {
            $response = $this->http->post(
                self::ENDPOINT,
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                ],
            );
        } catch (Throwable $throwable) {
            // Jamais le contenu de la clé ni celui du prompt dans un journal.
            $this->logger->warning('llm', 'Appel au modèle impossible', [
                'provider' => self::NAME,
                'prompt' => $prompt->fingerprint(),
            ]);

            return LlmResult::failure('llm.error.unreachable');
        }

        if ($response['status'] !== 200) {
            $this->logger->warning('llm', 'Réponse du modèle en erreur', [
                'provider' => self::NAME,
                'status' => $response['status'],
            ]);

            return LlmResult::failure('llm.error.status_' . $response['status']);
        }

        return $this->decode($response['body'], $prompt);
    }

    /**
     * Extrait la charge JSON du premier bloc de texte de la réponse.
     */
    private function decode(string $body, LlmPrompt $prompt): LlmResult
    {
        /** @var array<string, mixed>|null $envelope */
        $envelope = json_decode($body, true);
        if (!is_array($envelope)) {
            return LlmResult::failure('llm.error.malformed');
        }

        // Un refus de sécurité est une réponse valide côté HTTP : il faut le
        // reconnaître, pas le confondre avec une panne.
        if (($envelope['stop_reason'] ?? '') === 'refusal') {
            return LlmResult::failure('llm.error.refused');
        }

        $text = '';
        /** @var list<array<string, mixed>> $blocks */
        $blocks = is_array($envelope['content'] ?? null) ? $envelope['content'] : [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text = $block['text'];
                break;
            }
        }

        if ($text === '') {
            return LlmResult::failure('llm.error.empty');
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($text, true);
        if (!is_array($data)) {
            $this->logger->warning('llm', 'Sortie du modèle hors schéma', [
                'provider' => self::NAME,
                'prompt' => $prompt->fingerprint(),
            ]);

            return LlmResult::failure('llm.error.malformed');
        }

        /** @var array<string, mixed> $usage */
        $usage = is_array($envelope['usage'] ?? null) ? $envelope['usage'] : [];

        return LlmResult::success(
            $data,
            is_string($envelope['model'] ?? null) ? $envelope['model'] : $this->model,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
        );
    }
}
