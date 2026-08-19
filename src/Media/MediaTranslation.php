<?php

declare(strict_types=1);

namespace SecondStay\Media;

final class MediaTranslation
{
    public function __construct(
        public readonly string $locale,
        public readonly string $caption,
        public readonly string $altText,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['locale'],
            (string) ($row['caption'] ?? ''),
            (string) ($row['alt_text'] ?? ''),
        );
    }
}
