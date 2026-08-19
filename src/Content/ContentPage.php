<?php

declare(strict_types=1);

namespace SecondStay\Content;

/**
 * Page éditoriale et ses traductions.
 */
final class ContentPage
{
    /**
     * @param array<string, PageTranslation> $translations locale => traduction
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly string $slug,
        public readonly PageKind $kind,
        public readonly Season $season,
        public readonly int $position,
        public readonly bool $isPublished,
        public readonly bool $showInMenu,
        public readonly bool $isSystem,
        public readonly array $translations = [],
    ) {
    }

    /**
     * @param array<string, mixed>           $row
     * @param array<string, PageTranslation> $translations
     */
    public static function fromRow(array $row, array $translations = []): self
    {
        return new self(
            (int) $row['id'],
            $row['parent_id'] === null ? null : (int) $row['parent_id'],
            (string) $row['slug'],
            PageKind::fromString((string) $row['kind']),
            Season::fromString((string) $row['season']),
            (int) $row['position'],
            (bool) $row['is_published'],
            (bool) $row['show_in_menu'],
            (bool) $row['is_system'],
            $translations,
        );
    }

    /**
     * Traduction dans la langue demandée, avec repli déterministe.
     */
    public function translation(string $locale, string $fallback = 'fr'): ?PageTranslation
    {
        if (isset($this->translations[$locale])) {
            return $this->translations[$locale];
        }
        if (isset($this->translations[$fallback])) {
            return $this->translations[$fallback];
        }

        // Dernier recours : la première traduction disponible, jamais une clé
        // brute (I18N.md §5).
        $available = $this->translations;
        $first = reset($available);

        return $first === false ? null : $first;
    }

    public function hasTranslation(string $locale): bool
    {
        return isset($this->translations[$locale]) && $this->translations[$locale]->isComplete();
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
            $status[$locale] = $this->hasTranslation($locale);
        }

        return $status;
    }

    /**
     * @param array<string, PageTranslation> $translations
     */
    public function withTranslations(array $translations): self
    {
        return new self(
            $this->id,
            $this->parentId,
            $this->slug,
            $this->kind,
            $this->season,
            $this->position,
            $this->isPublished,
            $this->showInMenu,
            $this->isSystem,
            $translations,
        );
    }
}
