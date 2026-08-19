<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Content\Season;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Media\ImageProcessor;
use SecondStay\Media\MediaRepository;
use SecondStay\Media\MediaService;
use SecondStay\Tests\Support\DatabaseTestCase;

final class MediaServiceTest extends DatabaseTestCase
{
    private MediaService $media;

    private MediaRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('gd')) {
            self::markTestSkipped('Extension GD requise.');
        }

        $this->repository = new MediaRepository($this->database);
        $this->media = new MediaService(
            $this->repository,
            $this->paths,
            new ImageProcessor(),
            new AuditTrail($this->database),
        );
    }

    /**
     * Crée un JPEG réel, avec métadonnées si possible.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function jpegUpload(int $width = 900, int $height = 600, string $name = 'photo.jpg'): array
    {
        $path = $this->storagePath . '/temp/' . bin2hex(random_bytes(4)) . '.jpg';
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        $colour = imagecolorallocate($image, 40, 90, 160);
        imagefill($image, 0, 0, $colour === false ? 0 : $colour);
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return [
            'name' => $name,
            'type' => 'image/jpeg',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) (filesize($path) ?: 0),
        ];
    }

    public function testUploadCreatesEveryVariant(): void
    {
        $item = $this->media->upload($this->jpegUpload(), 'exterior', Season::Summer);

        self::assertSame('image/jpeg', $item->mimeType);
        self::assertSame('exterior', $item->category);
        self::assertSame(Season::Summer, $item->season);
        self::assertSame(900, $item->width);

        foreach (MediaService::VARIANTS as $variant) {
            self::assertTrue($this->media->variantExists($item->filename, $variant), $variant);
        }
    }

    public function testThumbnailIsResized(): void
    {
        $item = $this->media->upload($this->jpegUpload(2000, 1000));

        $thumb = getimagesize($this->media->variantPath($item->filename, 'thumb'));
        self::assertNotFalse($thumb);
        self::assertSame(ImageProcessor::THUMBNAIL_WIDTH, $thumb[0]);

        $large = getimagesize($this->media->variantPath($item->filename, 'large'));
        self::assertNotFalse($large);
        self::assertSame(ImageProcessor::LARGE_WIDTH, $large[0]);
    }

    public function testFilenameIsServerGeneratedAndNotDerivedFromInput(): void
    {
        $item = $this->media->upload($this->jpegUpload(400, 300, '../../evil.php.jpg'));

        self::assertMatchesRegularExpression('/^[a-z0-9]{16}\.jpg$/', $item->filename);
        self::assertStringNotContainsString('..', $item->filename);
        self::assertStringNotContainsString('evil', $item->filename);
    }

    public function testNonImageIsRejected(): void
    {
        $path = $this->storagePath . '/temp/payload.jpg';
        file_put_contents($path, "<?php echo 'pwned';");

        $this->expectException(ValidationException::class);
        $this->media->upload([
            'name' => 'payload.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
        ]);
    }

    public function testDeclaredMimeTypeIsIgnored(): void
    {
        $upload = $this->jpegUpload();
        $upload['type'] = 'image/svg+xml';

        $item = $this->media->upload($upload);

        self::assertSame('image/jpeg', $item->mimeType);
    }

    public function testOversizedUploadIsRejected(): void
    {
        $upload = $this->jpegUpload(100, 100);
        $upload['size'] = MediaService::MAX_UPLOAD_BYTES + 1;

        $this->expectException(ValidationException::class);
        $this->media->upload($upload);
    }

    public function testUploadErrorIsTranslated(): void
    {
        try {
            $this->media->upload(['error' => UPLOAD_ERR_INI_SIZE]);
            self::fail('Un téléversement en erreur doit être refusé.');
        } catch (ValidationException $exception) {
            self::assertSame('media.error.too_large', $exception->errors()['file']);
        }
    }

    public function testIdenticalFileIsNotDuplicated(): void
    {
        $upload = $this->jpegUpload();
        $copy = $this->storagePath . '/temp/copy.jpg';
        copy($upload['tmp_name'], $copy);

        $first = $this->media->upload($upload);
        $second = $this->media->upload(['name' => 'copy.jpg', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($copy)]);

        self::assertSame($first->id, $second->id);
        self::assertCount(1, $this->repository->all());
    }

    public function testVariantPathRefusesTraversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->media->variantPath('../../../etc/passwd', 'thumb');
    }

    public function testVariantPathRefusesUnknownVariant(): void
    {
        $item = $this->media->upload($this->jpegUpload(200, 200));

        $this->expectException(\RuntimeException::class);
        $this->media->variantPath($item->filename, 'huge');
    }

    public function testTranslationsAndAltText(): void
    {
        $item = $this->media->upload($this->jpegUpload(200, 200));
        $this->media->saveTranslations($item->id, [
            'fr' => ['caption' => 'Terrasse au soleil', 'alt_text' => 'Terrasse en bois'],
            'de' => ['caption' => 'Sonnige Terrasse', 'alt_text' => ''],
            'es' => ['caption' => 'Terraza', 'alt_text' => 'Terraza'],
        ]);

        $stored = $this->repository->findById($item->id);
        self::assertNotNull($stored);

        self::assertSame('Terrasse au soleil', $stored->caption('fr'));
        self::assertSame('Terrasse en bois', $stored->altText('fr'));
        // À défaut de texte alternatif, la légende est utilisée.
        self::assertSame('Sonnige Terrasse', $stored->altText('de'));
        // Locale inconnue : repli sur le français, jamais de chaîne vide.
        self::assertSame('Terrasse au soleil', $stored->caption('nl'));
        self::assertArrayNotHasKey('es', $stored->translations);

        $status = $stored->translationStatus(['fr', 'en', 'nl', 'de']);
        self::assertTrue($status['fr']);
        self::assertFalse($status['de']);
        self::assertFalse($status['nl']);
    }

    public function testPrivateAndUnpublishedMediaAreExcludedFromTheGallery(): void
    {
        $public = $this->media->upload($this->jpegUpload(210, 210));
        $private = $this->media->upload($this->jpegUpload(220, 220));
        $draft = $this->media->upload($this->jpegUpload(230, 230));

        $this->media->updateItem($private->id, ['is_private' => true]);
        $this->media->updateItem($draft->id, ['is_published' => false]);

        $ids = array_map(
            static fn (\SecondStay\Media\MediaItem $item): int => $item->id,
            $this->media->publicGallery(Season::Summer)
        );

        self::assertSame([$public->id], $ids);
    }

    public function testGalleryRespectsSeasonAndCategory(): void
    {
        $summer = $this->media->upload($this->jpegUpload(240, 240), 'garden', Season::Summer);
        $winter = $this->media->upload($this->jpegUpload(250, 250), 'garden', Season::Winter);
        $always = $this->media->upload($this->jpegUpload(260, 260), 'interior', Season::All);

        $summerIds = array_map(
            static fn (\SecondStay\Media\MediaItem $i): int => $i->id,
            $this->media->publicGallery(Season::Summer)
        );
        self::assertContains($summer->id, $summerIds);
        self::assertContains($always->id, $summerIds);
        self::assertNotContains($winter->id, $summerIds);

        $gardenIds = array_map(
            static fn (\SecondStay\Media\MediaItem $i): int => $i->id,
            $this->media->publicGallery(Season::Summer, 'garden')
        );
        self::assertSame([$summer->id], $gardenIds);
    }

    public function testDeleteRemovesEveryVariantFromDisk(): void
    {
        $item = $this->media->upload($this->jpegUpload(270, 270));
        $paths = array_map(
            fn (string $variant): string => $this->media->variantPath($item->filename, $variant),
            MediaService::VARIANTS
        );

        $this->media->delete($item->id);

        self::assertNull($this->repository->findById($item->id));
        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testCategoriesAreNormalised(): void
    {
        $item = $this->media->upload($this->jpegUpload(280, 280), '  Extérieur / Jardin  ');

        self::assertSame('exterieur-jardin', $item->category);
    }

    public function testUploadsAreAudited(): void
    {
        $this->media->upload($this->jpegUpload(290, 290), 'general', Season::All, false, 'owner@example.test', 1);

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');
        self::assertContains('media.uploaded', $actions);
    }
}
