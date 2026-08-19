<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Content\ContentPage;
use SecondStay\Content\ContentService;
use SecondStay\Content\PageKind;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;
use SecondStay\Media\MediaService;
use SecondStay\Seo\SeoBuilder;

/**
 * Rendu du site public à partir des contenus éditoriaux traduisibles.
 */
final class PageController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function home(RequestContext $context, array $params = []): Response
    {
        if (!$context->localePrefixPresent) {
            return $this->redirectToRoute($context, 'home');
        }

        $page = $this->content()->home();
        if ($page === null) {
            throw new NotFoundException('Aucune page d’accueil publiée.');
        }

        return $this->renderPage($context, $page, '/');
    }

    /**
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        $page = $this->content()->findPublished($slug);

        if ($page === null || $page->kind === PageKind::Home) {
            throw new NotFoundException('Page inconnue : ' . $slug);
        }

        return $this->renderPage($context, $page, '/' . $page->slug);
    }

    private function renderPage(RequestContext $context, ContentPage $page, string $path): Response
    {
        $locale = $context->locale;
        $translation = $page->translation($locale, Locales::FALLBACK);
        $seo = $this->container->get(SeoBuilder::class);

        $viewContext = [
            'page' => $page,
            'translation' => $translation,
            'meta_title' => $translation === null ? '' : $translation->effectiveMetaTitle(),
            'meta_description' => $translation === null ? '' : $translation->metaDescription,
            'is_fallback_translation' => !$page->hasTranslation($locale),
            'structured_data' => $page->kind === PageKind::Home
                ? json_encode($seo->structuredData($locale), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'season' => $this->content()->effectiveSeason()->value,
        ];

        if ($page->kind === PageKind::Gallery) {
            $viewContext += $this->galleryContext($context);
        }

        if ($page->kind === PageKind::Contact) {
            $viewContext['contact_email'] = $this->settings()->string('property.contact_email');
            $viewContext['contact_phone'] = $this->settings()->string('property.contact_phone');
        }

        if ($page->kind === PageKind::Home) {
            $viewContext['gallery_preview'] = array_slice($this->galleryContext($context)['gallery_items'], 0, 6);
        }

        return $this->render($page->kind->template(), $viewContext);
    }

    /**
     * @return array{gallery_items: list<array<string, mixed>>, gallery_categories: list<string>, gallery_category: string}
     */
    private function galleryContext(RequestContext $context): array
    {
        $media = $this->container->get(MediaService::class);
        $season = $this->content()->effectiveSeason();
        $category = trim((string) ($context->request->query('category') ?? ''));

        $items = $media->publicGallery($season, $category === '' ? null : $category);

        return [
            'gallery_items' => array_map(
                fn (\SecondStay\Media\MediaItem $item): array => [
                    'id' => $item->id,
                    'filename' => $item->filename,
                    'category' => $item->category,
                    'width' => $item->width,
                    'height' => $item->height,
                    'caption' => $item->caption($context->locale, Locales::FALLBACK),
                    'alt' => $item->altText($context->locale, Locales::FALLBACK),
                ],
                $items
            ),
            'gallery_categories' => $media->repository()->categories(),
            'gallery_category' => $category,
        ];
    }

    private function content(): ContentService
    {
        return $this->container->get(ContentService::class);
    }
}
