<?php

declare(strict_types=1);

namespace SecondStay\Content;

final class PageTranslation
{
    public function __construct(
        public readonly string $locale,
        public readonly string $title,
        public readonly string $menuLabel,
        public readonly string $lead,
        public readonly string $body,
        public readonly string $metaTitle,
        public readonly string $metaDescription,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['locale'],
            (string) ($row['title'] ?? ''),
            (string) ($row['menu_label'] ?? ''),
            (string) ($row['lead'] ?? ''),
            (string) ($row['body'] ?? ''),
            (string) ($row['meta_title'] ?? ''),
            (string) ($row['meta_description'] ?? ''),
        );
    }

    /**
     * Une traduction est complète lorsqu'elle possède au minimum un titre et
     * un corps : l'administration peut ainsi signaler ce qui reste à traduire.
     */
    public function isComplete(): bool
    {
        return trim($this->title) !== '' && trim($this->body) !== '';
    }

    public function effectiveMenuLabel(): string
    {
        return trim($this->menuLabel) !== '' ? $this->menuLabel : $this->title;
    }

    public function effectiveMetaTitle(): string
    {
        return trim($this->metaTitle) !== '' ? $this->metaTitle : $this->title;
    }
}
