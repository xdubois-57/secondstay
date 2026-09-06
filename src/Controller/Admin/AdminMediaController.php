<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Content\Season;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;
use SecondStay\Media\MediaItem;
use SecondStay\Media\MediaRepository;
use SecondStay\Media\MediaService;

final class AdminMediaController extends AdminController
{
    protected function section(): string
    {
        return 'media';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        return $this->renderLibrary([]);
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $media = $this->container->get(MediaService::class);

        /** @var array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file */
        $file = $context->request->files['media'] ?? ['error' => UPLOAD_ERR_NO_FILE];

        try {
            $item = $media->upload(
                $file,
                (string) ($context->request->input('category', 'general') ?? 'general'),
                Season::fromString((string) ($context->request->input('season', 'all') ?? 'all')),
                $context->request->input('is_private') !== null,
                $user->email,
                $user->id,
            );
        } catch (ValidationException $exception) {
            return $this->renderLibrary($exception->errors(), 422);
        }

        $this->flashSuccess('admin.media.uploaded');

        return $this->redirect(
            $context->request->basePath . $this->router()->path(
                'admin.media.edit',
                ['id' => $item->id],
                $context->locale,
            )
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        $item = $this->container->get(MediaRepository::class)->findById((int) ($params['id'] ?? 0));
        if ($item === null) {
            throw new \SecondStay\Core\Exception\NotFoundException('Média inconnu.');
        }

        return $this->renderEditor($item);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $media = $this->container->get(MediaService::class);
        $id = (int) ($params['id'] ?? 0);

        $media->updateItem($id, [
            'category' => $context->request->input('category', 'general'),
            'season' => $context->request->input('season', 'all'),
            'position' => (int) ($context->request->input('position', '0') ?? '0'),
            'is_published' => $context->request->input('is_published') !== null,
            'is_private' => $context->request->input('is_private') !== null,
        ], $user->email, $user->id);

        $translations = [];
        foreach (Locales::ALL as $locale) {
            $translations[$locale] = [
                'caption' => (string) ($context->request->input('caption_' . $locale, '') ?? ''),
                'alt_text' => (string) ($context->request->input('alt_' . $locale, '') ?? ''),
            ];
        }
        $media->saveTranslations($id, $translations);

        $this->flashSuccess('admin.media.saved');

        return $this->redirectToRoute($context, 'admin.media');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $this->container->get(MediaService::class)->delete((int) ($params['id'] ?? 0), $user->email, $user->id);
        $this->flashSuccess('admin.media.deleted');

        return $this->redirectToRoute($context, 'admin.media');
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderLibrary(array $errors, int $status = 200): Response
    {
        $repository = $this->container->get(MediaRepository::class);

        return $this->renderAdmin('admin/media.html.twig', [
            'meta_title' => $this->trans('admin.media.title'),
            'items' => array_map(
                fn (MediaItem $item): array => $this->present($item),
                $repository->all()
            ),
            'categories' => $repository->categories(),
            'seasons' => array_map(static fn (Season $s): string => $s->value, Season::cases()),
            'locales' => Locales::ALL,
            'max_bytes' => MediaService::MAX_UPLOAD_BYTES,
            'errors' => $errors,
        ], $status);
    }

    private function renderEditor(MediaItem $item): Response
    {
        $translations = [];
        foreach (Locales::ALL as $locale) {
            $translation = $item->translations[$locale] ?? null;
            $translations[$locale] = [
                'caption' => $translation === null ? '' : $translation->caption,
                'alt_text' => $translation === null ? '' : $translation->altText,
            ];
        }

        return $this->renderAdmin('admin/media-edit.html.twig', [
            'meta_title' => $this->trans('admin.media.edit'),
            'item' => $this->present($item),
            'translations' => $translations,
            'locales' => Locales::ALL,
            'seasons' => array_map(static fn (Season $s): string => $s->value, Season::cases()),
            'categories' => $this->container->get(MediaRepository::class)->categories(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MediaItem $item): array
    {
        return [
            'id' => $item->id,
            'filename' => $item->filename,
            'original_filename' => $item->originalFilename,
            'category' => $item->category,
            'season' => $item->season->value,
            'position' => $item->position,
            'is_published' => $item->isPublished,
            'is_private' => $item->isPrivate,
            'width' => $item->width,
            'height' => $item->height,
            'size' => $item->sizeBytes,
            'status' => $item->translationStatus(Locales::ALL),
        ];
    }
}
