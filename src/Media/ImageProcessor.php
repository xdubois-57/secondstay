<?php

declare(strict_types=1);

namespace SecondStay\Media;

use RuntimeException;

/**
 * Traitement d'image (SPECIFICATIONS.md §8, SECURITY.md §9).
 *
 * Les images téléversées sont ré-encodées : cela supprime toute charge
 * embarquée (métadonnées exécutables, EXIF, GPS) et normalise l'orientation.
 */
final class ImageProcessor
{
    public const THUMBNAIL_WIDTH = 480;
    public const LARGE_WIDTH = 1600;

    /** @var array<string, string> MIME => extension */
    public const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * @return array{mime: string, extension: string, width: int, height: int}
     */
    public function inspect(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException('media.error.not_an_image');
        }

        $mime = (string) $info['mime'];
        if (!isset(self::ALLOWED_TYPES[$mime])) {
            throw new RuntimeException('media.error.unsupported_type');
        }

        return [
            'mime' => $mime,
            'extension' => self::ALLOWED_TYPES[$mime],
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    /**
     * Ré-encode l'image à la largeur demandée en respectant l'orientation EXIF
     * et en supprimant toutes les métadonnées, GPS compris.
     *
     * @return array{width: int, height: int}
     */
    public function reencode(string $source, string $destination, int $maxWidth, int $quality = 82): array
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('media.error.gd_missing');
        }

        $info = $this->inspect($source);
        $image = $this->load($source, $info['mime']);

        try {
            $image = $this->applyExifOrientation($image, $source, $info['mime']);

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > $maxWidth) {
                $ratio = $maxWidth / $width;
                $targetHeight = max(1, (int) round($height * $ratio));
                $targetWidth = max(1, $maxWidth);
                $resized = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                imagedestroy($image);
                $image = $resized;
                $width = $targetWidth;
                $height = $targetHeight;
            }

            $directory = dirname($destination);
            if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
                throw new RuntimeException('media.error.storage_unwritable');
            }

            $saved = match ($info['mime']) {
                'image/png' => imagepng($image, $destination, 6),
                'image/webp' => imagewebp($image, $destination, $quality),
                'image/avif' => function_exists('imageavif')
                    ? imageavif($image, $destination, $quality)
                    : imagejpeg($image, $destination, $quality),
                default => imagejpeg($image, $destination, $quality),
            };

            if ($saved === false) {
                throw new RuntimeException('media.error.encoding_failed');
            }

            @chmod($destination, 0o640);

            return ['width' => $width, 'height' => $height];
        } finally {
            if (is_resource($image) || $image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    private function load(string $path, string $mime): \GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('media.error.unreadable');
        }

        return $image;
    }

    private function applyExifOrientation(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated === false || $rotated === null) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }
}
