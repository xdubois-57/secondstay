<?php

declare(strict_types=1);

namespace SecondStay\Media;

use SecondStay\Content\Season;

final class MediaItem
{
    /**
     * @param array<string, MediaTranslation> $translations
     */
    public function __construct(
        public readonly int $id,
        public readonly string $filename,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly int $width,
        public readonly int $height,
        public readonly string $category,
        public readonly Season $season,
        public readonly int $position,
        public readonly bool $isPublished,
        public readonly bool $isPrivate,
        public readonly string $hash,
        public readonly array $translations = [],
    ) {
    }

    /**
     * @param array<string, mixed>            $row
     * @param array<string, MediaTranslation> $translations
     */
    public static function fromRow(array $row, array $translations = []): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['filename'],
            (string) ($row['original_filename'] ?? ''),
            (string) $row['mime_type'],
            (int) $row['size_bytes'],
            (int) $row['width'],
            (int) $row['height'],
            (string) $row['category'],
            Season::fromString((string) $row['season']),
            (int) $row['position'],
            (bool) $row['is_published'],
            (bool) $row['is_private'],
            (string) ($row['hash'] ?? ''),
            $translations,
        );
    }

    public function translation(string $locale, string $fallback = 'fr'): ?MediaTranslation
    {
        if (isset($this->translations[$locale])) {
            return $this->translations[$locale];
        }
        if (isset($this->translations[$fallback])) {
            return $this->translations[$fallback];
        }

        $available = $this->translations;
        $first = reset($available);

        return $first === false ? null : $first;
    }

    public function caption(string $locale, string $fallback = 'fr'): string
    {
        $translation = $this->translation($locale, $fallback);

        return $translation === null ? '' : $translation->caption;
    }

    public function altText(string $locale, string $fallback = 'fr'): string
    {
        $translation = $this->translation($locale, $fallback);
        if ($translation === null) {
            return '';
        }

        // Le texte alternatif prime ; à défaut la légende reste préférable à
        // une image sans description.
        return $translation->altText !== '' ? $translation->altText : $translation->caption;
    }

    /**
     * @param list<string> $locales
     *
     * @return array<string, bool>
     */
    public function translationStatus(array $locales): array
    {
        $status = [];
        foreach ($locales as $locale) {
            $translation = $this->translations[$locale] ?? null;
            $status[$locale] = $translation !== null && trim($translation->altText) !== '';
        }

        return $status;
    }

    public function aspectRatio(): float
    {
        return $this->height > 0 ? $this->width / $this->height : 1.0;
    }
}
