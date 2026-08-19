<?php

declare(strict_types=1);

namespace SecondStay\Content;

use SecondStay\Database\Database;
use SecondStay\I18n\Locales;
use SecondStay\I18n\Translator;

/**
 * Crée l'arborescence de contenu par défaut, traduite dans les quatre langues.
 *
 * Idempotent : une page déjà présente n'est jamais écrasée.
 */
final class ContentSeeder
{
    public function __construct(
        private readonly ContentRepository $repository,
        private readonly Translator $translator,
        private readonly Database $database,
    ) {
    }

    /**
     * @return list<string> slugs créés
     */
    public function seed(): array
    {
        $created = [];
        $position = 0;

        foreach (DefaultContent::pages() as $definition) {
            $position += 10;

            if ($this->repository->findBySlug($definition['slug']) !== null) {
                continue;
            }

            $parentId = null;
            if ($definition['parent'] !== null) {
                $parentId = $this->repository->findBySlug($definition['parent'])?->id;
            }

            $id = $this->repository->create([
                'parent_id' => $parentId,
                'slug' => $definition['slug'],
                'kind' => $definition['kind']->value,
                'season' => $definition['season']->value,
                'position' => $position,
                'is_published' => 1,
                'show_in_menu' => $definition['show_in_menu'] ? 1 : 0,
                'is_system' => $definition['is_system'] ? 1 : 0,
            ]);

            foreach (Locales::ALL as $locale) {
                $prefix = 'content.default.' . $definition['key'] . '.';

                $this->repository->saveTranslation($id, $locale, [
                    'title' => $this->translator->trans($prefix . 'title', [], $locale),
                    'menu_label' => $this->translator->trans($prefix . 'menu', [], $locale),
                    'lead' => $this->translator->trans($prefix . 'lead', [], $locale),
                    'body' => $this->translator->trans($prefix . 'body', [], $locale),
                    'meta_title' => $this->translator->trans($prefix . 'title', [], $locale),
                    'meta_description' => $this->translator->trans($prefix . 'lead', [], $locale),
                ]);
            }

            $created[] = $definition['slug'];
        }

        return $created;
    }

    public function hasContent(): bool
    {
        return $this->database->tableExists('content_page')
            && (int) $this->database->fetchValue('SELECT COUNT(*) FROM `content_page`') > 0;
    }
}
