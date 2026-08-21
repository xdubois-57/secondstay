<?php

declare(strict_types=1);

namespace SecondStay\Llm;

/**
 * Aucun modèle configuré.
 *
 * C'est l'état par défaut d'une installation : le contenu local est une
 * fonction facultative, et une installation sans clé doit fonctionner sans
 * jamais faire semblant d'en avoir une.
 */
final class NullLlmProvider implements LlmProvider
{
    public const NAME = 'none';

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function complete(LlmPrompt $prompt): LlmResult
    {
        return LlmResult::failure('llm.error.not_configured');
    }
}
