<?php

declare(strict_types=1);

namespace SecondStay\Content;

use SecondStay\Audit\AuditTrail;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\I18n\Locales;
use SecondStay\Settings\SettingsService;
use SecondStay\Support\HtmlSanitizer;
use SecondStay\Support\Slugger;

/**
 * Gestion des contenus éditoriaux traduisibles.
 *
 * Les textes système restent dans les catalogues de traduction ; seuls les
 * contenus rédigés par le propriétaire vivent ici (I18N.md §2).
 */
final class ContentService
{
    public function __construct(
        private readonly ContentRepository $repository,
        private readonly SettingsService $settings,
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    public function effectiveSeason(): Season
    {
        return Season::resolve($this->settings->string('site.season'));
    }

    /**
     * @return list<ContentPage>
     */
    public function allPages(): array
    {
        return $this->repository->all();
    }

    /**
     * Pages visibles publiquement pour la saison effective.
     *
     * @return list<ContentPage>
     */
    public function visiblePages(): array
    {
        $season = $this->effectiveSeason();

        return array_values(array_filter(
            $this->repository->published(),
            static fn (ContentPage $page): bool => $page->season->matches($season)
        ));
    }

    public function findPublished(string $slug): ?ContentPage
    {
        $page = $this->repository->findBySlug($slug);
        if ($page === null || !$page->isPublished) {
            return null;
        }

        return $page->season->matches($this->effectiveSeason()) ? $page : null;
    }

    public function home(): ?ContentPage
    {
        $page = $this->repository->findByKind(PageKind::Home);

        return $page !== null && $page->isPublished ? $page : null;
    }

    /**
     * Arbre de navigation multi-niveaux, filtré par publication et saison.
     *
     * @return list<array{page: ContentPage, children: list<array{page: ContentPage, children: list<mixed>}>}>
     */
    public function menuTree(): array
    {
        $pages = array_values(array_filter(
            $this->visiblePages(),
            static fn (ContentPage $page): bool => $page->showInMenu
        ));

        /** @var array<int, list<ContentPage>> $byParent */
        $byParent = [];
        foreach ($pages as $page) {
            $byParent[$page->parentId ?? 0][] = $page;
        }

        return $this->buildBranch($byParent, 0);
    }

    /**
     * @param array<int, list<ContentPage>> $byParent
     *
     * @return list<array{page: ContentPage, children: list<mixed>}>
     */
    private function buildBranch(array $byParent, int $parentId, int $depth = 0): array
    {
        if ($depth > 3 || !isset($byParent[$parentId])) {
            return [];
        }

        $branch = [];
        foreach ($byParent[$parentId] as $page) {
            $branch[] = [
                'page' => $page,
                'children' => $this->buildBranch($byParent, $page->id, $depth + 1),
            ];
        }

        return $branch;
    }

    /**
     * Menu prêt pour le rendu, dans la langue demandée.
     *
     * @return list<array{slug: string, label: string, children: list<mixed>}>
     */
    public function menuForView(string $locale): array
    {
        return $this->flattenForView($this->menuTree(), $locale);
    }

    /**
     * @param list<array{page: ContentPage, children: list<mixed>}> $branch
     *
     * @return list<array{slug: string, label: string, children: list<mixed>}>
     */
    private function flattenForView(array $branch, string $locale): array
    {
        $items = [];
        foreach ($branch as $node) {
            $page = $node['page'];
            if ($page->kind === PageKind::Home) {
                continue;
            }
            $translation = $page->translation($locale, Locales::FALLBACK);
            $items[] = [
                'slug' => $page->slug,
                'label' => $translation === null ? $page->slug : $translation->effectiveMenuLabel(),
                /** @var list<mixed> */
                'children' => $this->flattenForView($node['children'], $locale),
            ];
        }

        return $items;
    }

    /**
     * Liens légaux du pied de page.
     *
     * @return list<array{slug: string, label: string}>
     */
    public function legalLinks(string $locale): array
    {
        $links = [];
        foreach ($this->visiblePages() as $page) {
            if (!$page->kind->isLegal()) {
                continue;
            }
            $translation = $page->translation($locale, Locales::FALLBACK);
            $links[] = [
                'slug' => $page->slug,
                'label' => $translation === null ? $page->slug : $translation->effectiveMenuLabel(),
            ];
        }

        return $links;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @throws ValidationException
     */
    public function createPage(array $attributes, ?string $actorLabel = null, ?int $actorId = null): int
    {
        $slug = $this->normaliseSlug((string) ($attributes['slug'] ?? ''));
        if ($slug === '') {
            throw new ValidationException(['slug' => 'content.error.slug_required']);
        }
        if ($this->repository->slugExists($slug)) {
            throw new ValidationException(['slug' => 'content.error.slug_taken']);
        }

        $parentId = isset($attributes['parent_id']) && (int) $attributes['parent_id'] > 0
            ? (int) $attributes['parent_id']
            : null;

        $id = $this->repository->create([
            'parent_id' => $parentId,
            'slug' => $slug,
            'kind' => PageKind::fromString((string) ($attributes['kind'] ?? 'page'))->value,
            'season' => Season::fromString((string) ($attributes['season'] ?? 'all'))->value,
            'position' => $this->repository->nextPosition($parentId),
            'is_published' => (bool) ($attributes['is_published'] ?? false) ? 1 : 0,
            'show_in_menu' => (bool) ($attributes['show_in_menu'] ?? true) ? 1 : 0,
            'is_system' => 0,
        ]);

        $this->audit?->record('content.page_created', 'content_page', (string) $id, null, ['slug' => $slug], $actorId, $actorLabel ?? 'system');

        return $id;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @throws ValidationException
     */
    public function updatePage(int $id, array $attributes, ?string $actorLabel = null, ?int $actorId = null): void
    {
        $page = $this->repository->findById($id);
        if ($page === null) {
            throw new ValidationException(['id' => 'content.error.not_found']);
        }

        $data = [];

        if (isset($attributes['slug']) && !$page->isSystem) {
            $slug = $this->normaliseSlug((string) $attributes['slug']);
            if ($slug === '') {
                throw new ValidationException(['slug' => 'content.error.slug_required']);
            }
            if ($this->repository->slugExists($slug, $id)) {
                throw new ValidationException(['slug' => 'content.error.slug_taken']);
            }
            $data['slug'] = $slug;
        }

        if (isset($attributes['season'])) {
            $data['season'] = Season::fromString((string) $attributes['season'])->value;
        }
        if (array_key_exists('is_published', $attributes)) {
            $data['is_published'] = (bool) $attributes['is_published'] ? 1 : 0;
        }
        if (array_key_exists('show_in_menu', $attributes)) {
            $data['show_in_menu'] = (bool) $attributes['show_in_menu'] ? 1 : 0;
        }
        if (array_key_exists('position', $attributes)) {
            $data['position'] = (int) $attributes['position'];
        }
        if (array_key_exists('parent_id', $attributes) && !$page->isSystem) {
            $parentId = (int) $attributes['parent_id'];
            if ($parentId === $id) {
                throw new ValidationException(['parent_id' => 'content.error.parent_self']);
            }
            $data['parent_id'] = $parentId > 0 ? $parentId : null;
        }

        if ($data !== []) {
            $this->repository->update($id, $data);
            $this->audit?->record(
                'content.page_updated',
                'content_page',
                (string) $id,
                ['is_published' => $page->isPublished],
                $data,
                $actorId,
                $actorLabel ?? 'system'
            );
        }
    }

    /**
     * Enregistre les traductions fournies. Le corps riche est assaini.
     *
     * @param array<string, array<string, string>> $translations locale => champs
     */
    public function saveTranslations(int $pageId, array $translations, ?string $actorLabel = null, ?int $actorId = null): void
    {
        foreach ($translations as $locale => $values) {
            if (!Locales::isSupported($locale)) {
                continue;
            }

            $this->repository->saveTranslation($pageId, $locale, [
                'title' => trim($values['title'] ?? ''),
                'menu_label' => trim($values['menu_label'] ?? ''),
                'lead' => trim($values['lead'] ?? ''),
                'body' => $this->sanitizer->sanitize($values['body'] ?? ''),
                'meta_title' => trim($values['meta_title'] ?? ''),
                'meta_description' => mb_substr(trim($values['meta_description'] ?? ''), 0, 320),
            ]);
        }

        $this->audit?->record(
            'content.translations_saved',
            'content_page',
            (string) $pageId,
            null,
            ['locales' => array_keys($translations)],
            $actorId,
            $actorLabel ?? 'system'
        );
    }

    public function deletePage(int $id, ?string $actorLabel = null, ?int $actorId = null): void
    {
        $page = $this->repository->findById($id);
        if ($page === null) {
            return;
        }
        if ($page->isSystem) {
            throw new ValidationException(['id' => 'content.error.system_page']);
        }

        $this->repository->delete($id);
        $this->audit?->record('content.page_deleted', 'content_page', (string) $id, ['slug' => $page->slug], null, $actorId, $actorLabel ?? 'system');
    }

    /**
     * État de complétude des traductions, pour l'administration (I18N.md §10).
     *
     * @return list<array{page: ContentPage, status: array<string, bool>, complete: bool}>
     */
    public function translationOverview(): array
    {
        $overview = [];
        foreach ($this->repository->all() as $page) {
            $status = $page->translationStatus(Locales::ALL);
            $overview[] = [
                'page' => $page,
                'status' => $status,
                'complete' => !in_array(false, $status, true),
            ];
        }

        return $overview;
    }

    public function normaliseSlug(string $value): string
    {
        return Slugger::slug($value);
    }

    public function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer;
    }
}
