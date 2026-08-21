<?php

declare(strict_types=1);

namespace SecondStay\Llm;

/**
 * Réponse d'un fournisseur, déjà décodée.
 */
final class LlmResult
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $data,
        public readonly string $error,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $model,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function success(
        array $data,
        string $model = '',
        int $inputTokens = 0,
        int $outputTokens = 0,
    ): self {
        return new self(true, $data, '', $inputTokens, $outputTokens, $model);
    }

    public static function failure(string $error): self
    {
        return new self(false, [], $error, 0, 0, '');
    }
}
