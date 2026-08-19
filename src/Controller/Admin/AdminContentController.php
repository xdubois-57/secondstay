<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Content\ContentService;
use SecondStay\Content\PageKind;
use SecondStay\Content\Season;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;

/**
 * Administration des contenus éditoriaux, avec état de complétude des
 * traductions FR/EN/NL/DE (I18N.md §10).
 */
final class AdminContentController extends AdminController
{
    protected function section(): string
    {
        return 'content';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();
        $content = $this->content();

        return $this->renderAdmin('admin/content.html.twig', [
            'meta_title' => $this->trans('admin.content.title'),
            'overview' => array_map(
                static function (array $entry): array {
                    $reference = $entry['page']->translation(Locales::FALLBACK);

                    return [
                    'id' => $entry['page']->id,
                    'slug' => $entry['page']->slug,
                    'kind' => $entry['page']->kind->value,
                    'season' => $entry['page']->season->value,
                    'position' => $entry['page']->position,
                    'parent_id' => $entry['page']->parentId,
                    'is_published' => $entry['page']->isPublished,
                    'show_in_menu' => $entry['page']->showInMenu,
                    'is_system' => $entry['page']->isSystem,
                    'title' => $reference === null ? '' : $reference->title,
                    'status' => $entry['status'],
                    'complete' => $entry['complete'],
                    ];
                },
                $content->translationOverview()
            ),
            'locales' => Locales::ALL,
            'kinds' => array_map(static fn (PageKind $k): string => $k->value, PageKind::cases()),
            'seasons' => array_map(static fn (Season $s): string => $s->value, Season::cases()),
            'effective_season' => $content->effectiveSeason()->value,
            'errors' => [],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        return $this->renderEditor($context, (int) ($params['id'] ?? 0), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $id = (int) ($params['id'] ?? 0);
        $content = $this->content();

        $translations = [];
        foreach (Locales::ALL as $locale) {
            $translations[$locale] = [
                'title' => (string) ($context->request->input('title_' . $locale, '') ?? ''),
                'menu_label' => (string) ($context->request->input('menu_label_' . $locale, '') ?? ''),
                'lead' => (string) ($context->request->input('lead_' . $locale, '') ?? ''),
                'body' => (string) ($context->request->input('body_' . $locale, '') ?? ''),
                'meta_title' => (string) ($context->request->input('meta_title_' . $locale, '') ?? ''),
                'meta_description' => (string) ($context->request->input('meta_description_' . $locale, '') ?? ''),
            ];
        }

        try {
            $content->updatePage($id, [
                'slug' => $context->request->input('slug'),
                'season' => $context->request->input('season', 'all'),
                'is_published' => $context->request->input('is_published') !== null,
                'show_in_menu' => $context->request->input('show_in_menu') !== null,
                'position' => (int) ($context->request->input('position', '0') ?? '0'),
                'parent_id' => (int) ($context->request->input('parent_id', '0') ?? '0'),
            ], $user->email, $user->id);

            $content->saveTranslations($id, $translations, $user->email, $user->id);
        } catch (ValidationException $exception) {
            return $this->renderEditor($context, $id, $exception->errors(), 422);
        }

        $this->flashSuccess('admin.content.saved');

        return $this->redirect(
            $context->request->basePath . $this->router()->path('admin.content.edit', ['id' => $id], $context->locale)
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function create(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        try {
            $id = $this->content()->createPage([
                'slug' => $context->request->input('slug', ''),
                'kind' => $context->request->input('kind', 'page'),
                'season' => $context->request->input('season', 'all'),
                'is_published' => false,
                'show_in_menu' => true,
            ], $user->email, $user->id);
        } catch (ValidationException $exception) {
            $this->flashError($exception->errors()['slug'] ?? 'content.error.slug_required');

            return $this->redirectToRoute($context, 'admin.content');
        }

        $this->flashSuccess('admin.content.created');

        return $this->redirect(
            $context->request->basePath . $this->router()->path('admin.content.edit', ['id' => $id], $context->locale)
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        try {
            $this->content()->deletePage((int) ($params['id'] ?? 0), $user->email, $user->id);
            $this->flashSuccess('admin.content.deleted');
        } catch (ValidationException $exception) {
            $this->flashError($exception->errors()['id'] ?? 'content.error.not_found');
        }

        return $this->redirectToRoute($context, 'admin.content');
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderEditor(RequestContext $context, int $id, array $errors, int $status = 200): Response
    {
        $content = $this->content();
        $page = null;
        foreach ($content->allPages() as $candidate) {
            if ($candidate->id === $id) {
                $page = $candidate;
                break;
            }
        }

        if ($page === null) {
            throw new NotFoundException('Page inconnue.');
        }

        $translations = [];
        foreach (Locales::ALL as $locale) {
            $translation = $page->translations[$locale] ?? null;
            $translations[$locale] = $translation === null
                ? [
                    'title' => '',
                    'menu_label' => '',
                    'lead' => '',
                    'body' => '',
                    'meta_title' => '',
                    'meta_description' => '',
                    'complete' => false,
                ]
                : [
                    'title' => $translation->title,
                    'menu_label' => $translation->menuLabel,
                    'lead' => $translation->lead,
                    'body' => $translation->body,
                    'meta_title' => $translation->metaTitle,
                    'meta_description' => $translation->metaDescription,
                    'complete' => $translation->isComplete(),
                ];
        }

        $parents = [];
        foreach ($content->allPages() as $candidate) {
            if ($candidate->id === $page->id || $candidate->kind === PageKind::Home) {
                continue;
            }
            $candidateTranslation = $candidate->translation(Locales::FALLBACK);
            $parents[] = [
                'id' => $candidate->id,
                'label' => $candidateTranslation === null ? $candidate->slug : $candidateTranslation->title,
            ];
        }

        return $this->renderAdmin('admin/content-edit.html.twig', [
            'meta_title' => $this->trans('admin.content.edit'),
            'page' => [
                'id' => $page->id,
                'slug' => $page->slug,
                'kind' => $page->kind->value,
                'season' => $page->season->value,
                'position' => $page->position,
                'parent_id' => $page->parentId,
                'is_published' => $page->isPublished,
                'show_in_menu' => $page->showInMenu,
                'is_system' => $page->isSystem,
            ],
            'translations' => $translations,
            'locales' => Locales::ALL,
            'seasons' => array_map(static fn (Season $s): string => $s->value, Season::cases()),
            'parents' => $parents,
            'errors' => $errors,
        ], $status);
    }

    private function content(): ContentService
    {
        return $this->container->get(ContentService::class);
    }
}
