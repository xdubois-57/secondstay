<?php

declare(strict_types=1);

namespace SecondStay\LocalContent;

/**
 * Une page récupérée, réduite à son texte.
 */
final class SourceDocument
{
    public function __construct(
        public readonly int $sourceId,
        public readonly string $url,
        public readonly string $label,
        public readonly string $text,
    ) {
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }
}
