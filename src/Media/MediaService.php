<?php

declare(strict_types=1);

namespace SecondStay\Media;

use RuntimeException;
use SecondStay\Audit\AuditTrail;
use SecondStay\Content\Season;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Paths;
use SecondStay\Quota\QuotaService;
use SecondStay\I18n\Locales;
use SecondStay\Support\Slugger;

/**
 * Bibliothèque de médias : téléversement, variantes et diffusion contrôlée.
 *
 * Les fichiers sont stockés hors racine web (`storage/media`) et servis
 * uniquement par un endpoint applicatif : le nom de fichier est généré par le
 * serveur, jamais fourni par le client (SECURITY.md §9).
 */
final class MediaService
{
    public const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;

    /** @var list<string> */
    public const VARIANTS = ['thumb', 'large', 'original'];

    public function __construct(
        private readonly MediaRepository $repository,
        private readonly Paths $paths,
        private readonly ImageProcessor $processor = new ImageProcessor(),
        private readonly ?AuditTrail $audit = null,
        private readonly ?QuotaService $quotas = null,
    ) {
    }

    /**
     * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
     *
     * @throws ValidationException
     */
    public function upload(
        array $file,
        string $category = 'general',
        Season $season = Season::All,
        bool $isPrivate = false,
        ?string $actorLabel = null,
        ?int $actorId = null,
    ): MediaItem {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'media.error.too_large',
                UPLOAD_ERR_NO_FILE => 'media.error.no_file',
                default => 'media.error.upload_failed',
            }]);
        }

        $temporary = (string) ($file['tmp_name'] ?? '');
        if ($temporary === '' || !is_file($temporary)) {
            throw new ValidationException(['file' => 'media.error.no_file']);
        }

        $size = (int) ($file['size'] ?? filesize($temporary) ?: 0);
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new ValidationException(['file' => 'media.error.too_large']);
        }

        // Le quota est vérifié avant d'écrire : une galerie ne doit pas
        // remplir le disque au point d'empêcher une sauvegarde.
        if ($this->quotas !== null && !$this->quotas->allows('media', $size)) {
            throw new ValidationException(['file' => 'quota.error.media']);
        }

        try {
            // Le type déclaré par le client n'est jamais utilisé : seul le
            // contenu réel du fichier fait foi.
            $info = $this->processor->inspect($temporary);
        } catch (RuntimeException $exception) {
            throw new ValidationException(['file' => $exception->getMessage()]);
        }

        $hash = hash_file('sha256', $temporary);
        $hash = $hash === false ? '' : $hash;

        $existing = $hash === '' ? null : $this->repository->findByHash($hash);
        if ($existing !== null) {
            return $existing;
        }

        $filename = $this->generateFilename($info['extension']);

        try {
            $original = $this->processor->reencode(
                $temporary,
                $this->variantPath($filename, 'original'),
                ImageProcessor::LARGE_WIDTH * 2,
            );
            $this->processor->reencode($temporary, $this->variantPath($filename, 'large'), ImageProcessor::LARGE_WIDTH);
            $this->processor->reencode(
                $temporary,
                $this->variantPath($filename, 'thumb'),
                ImageProcessor::THUMBNAIL_WIDTH,
            );
        } catch (RuntimeException $exception) {
            throw new ValidationException(['file' => $exception->getMessage()]);
        }

        $id = $this->repository->create([
            'filename' => $filename,
            'original_filename' => mb_substr($this->safeOriginalName((string) ($file['name'] ?? '')), 0, 190),
            'mime_type' => $info['mime'],
            'size_bytes' => (int) (filesize($this->variantPath($filename, 'original')) ?: $size),
            'width' => $original['width'],
            'height' => $original['height'],
            'category' => $this->normaliseCategory($category),
            'season' => $season->value,
            'position' => $this->repository->nextPosition(),
            'is_published' => 1,
            'is_private' => $isPrivate ? 1 : 0,
            'hash' => $hash,
        ]);

        $this->audit?->record(
            'media.uploaded',
            'media',
            (string) $id,
            null,
            ['category' => $category, 'mime' => $info['mime'], 'bytes' => $size],
            $actorId,
            $actorLabel ?? 'system'
        );

        $item = $this->repository->findById($id);
        if ($item === null) {
            throw new RuntimeException('Média introuvable après création.');
        }

        return $item;
    }

    /**
     * @param array<string, array{caption?: string, alt_text?: string}> $translations
     */
    public function saveTranslations(int $mediaId, array $translations): void
    {
        foreach ($translations as $locale => $values) {
            if (!Locales::isSupported($locale)) {
                continue;
            }

            $this->repository->saveTranslation(
                $mediaId,
                $locale,
                mb_substr(trim($values['caption'] ?? ''), 0, 255),
                mb_substr(trim($values['alt_text'] ?? ''), 0, 255),
            );
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateItem(int $id, array $attributes, ?string $actorLabel = null, ?int $actorId = null): void
    {
        $data = [];

        if (isset($attributes['category'])) {
            $data['category'] = $this->normaliseCategory((string) $attributes['category']);
        }
        if (isset($attributes['season'])) {
            $data['season'] = Season::fromString((string) $attributes['season'])->value;
        }
        if (array_key_exists('position', $attributes)) {
            $data['position'] = (int) $attributes['position'];
        }
        if (array_key_exists('is_published', $attributes)) {
            $data['is_published'] = (bool) $attributes['is_published'] ? 1 : 0;
        }
        if (array_key_exists('is_private', $attributes)) {
            $data['is_private'] = (bool) $attributes['is_private'] ? 1 : 0;
        }

        if ($data !== []) {
            $this->repository->update($id, $data);
            $this->audit?->record(
                'media.updated',
                'media',
                (string) $id,
                null,
                $data,
                $actorId,
                $actorLabel ?? 'system',
            );
        }
    }

    public function delete(int $id, ?string $actorLabel = null, ?int $actorId = null): void
    {
        $item = $this->repository->findById($id);
        if ($item === null) {
            return;
        }

        foreach (self::VARIANTS as $variant) {
            $path = $this->variantPath($item->filename, $variant);
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->repository->delete($id);
        $this->audit?->record(
            'media.deleted',
            'media',
            (string) $id,
            ['filename' => $item->filename],
            null,
            $actorId,
            $actorLabel ?? 'system',
        );
    }

    /**
     * Chemin physique d'une variante. Le nom de fichier est validé : aucune
     * traversée de chemin n'est possible.
     */
    public function variantPath(string $filename, string $variant): string
    {
        if (preg_match('/^[a-z0-9]{16}\.[a-z0-9]{2,5}$/', $filename) !== 1) {
            throw new RuntimeException('Nom de média invalide.');
        }
        if (!in_array($variant, self::VARIANTS, true)) {
            throw new RuntimeException('Variante de média inconnue.');
        }

        return $this->paths->storage('media/' . $variant . '/' . $filename);
    }

    public function variantExists(string $filename, string $variant): bool
    {
        return is_file($this->variantPath($filename, $variant));
    }

    /**
     * @return list<MediaItem>
     */
    public function publicGallery(Season $effectiveSeason, ?string $category = null): array
    {
        return array_values(array_filter(
            $this->repository->published($category),
            static fn (MediaItem $item): bool => $item->season->matches($effectiveSeason)
        ));
    }

    public function repository(): MediaRepository
    {
        return $this->repository;
    }

    private function generateFilename(string $extension): string
    {
        do {
            $filename = bin2hex(random_bytes(8)) . '.' . $extension;
        } while ($this->repository->findByFilename($filename) !== null);

        return $filename;
    }

    private function safeOriginalName(string $name): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name);

        return $clean === null ? 'image' : trim($clean);
    }

    private function normaliseCategory(string $category): string
    {
        $clean = Slugger::slug($category, 48);

        return $clean === '' ? 'general' : $clean;
    }
}
