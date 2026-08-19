<?php

declare(strict_types=1);

namespace SecondStay\Pwa;

use RuntimeException;

/**
 * Icônes d'application générées par l'installation.
 *
 * Aucune icône n'est versionnée : elle est dessinée à partir du nom du
 * logement et de la couleur de thème, puis mise en cache dans `storage/`. Le
 * dépôt public reste ainsi exempt de contenu propre à une résidence.
 */
final class IconGenerator
{
    public const ALLOWED_SIZES = [192, 512];
    public const BACKGROUND = [13, 110, 253];

    public function __construct(private readonly string $cacheDirectory)
    {
    }

    /**
     * Renvoie le chemin d'une icône PNG, en la générant si nécessaire.
     */
    public function icon(string $label, int $size, bool $maskable = false): string
    {
        if (!in_array($size, self::ALLOWED_SIZES, true)) {
            throw new RuntimeException('pwa.error.unsupported_size');
        }

        $initials = self::initials($label);
        $file = $this->cacheDirectory . '/icon-' . ($maskable ? 'maskable-' : '') . $size
            . '-' . substr(hash('sha256', $initials), 0, 12) . '.png';

        if (is_file($file)) {
            return $file;
        }

        if (!is_dir($this->cacheDirectory) && !mkdir($this->cacheDirectory, 0o750, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException('pwa.error.cache_unavailable');
        }

        $this->draw($initials, $size, $maskable, $file);

        return $file;
    }

    /**
     * Une icône masquable doit garder son contenu dans la zone sûre centrale
     * (80 % du côté) : le texte y est donc plus petit.
     */
    private function draw(string $initials, int $size, bool $maskable, string $file): void
    {
        if ($size < 1) {
            throw new RuntimeException('pwa.error.unsupported_size');
        }

        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            throw new RuntimeException('pwa.error.generation_failed');
        }

        $background = imagecolorallocate($image, self::BACKGROUND[0], self::BACKGROUND[1], self::BACKGROUND[2]);
        $foreground = imagecolorallocate($image, 255, 255, 255);
        if ($background === false || $foreground === false) {
            imagedestroy($image);

            throw new RuntimeException('pwa.error.generation_failed');
        }

        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $background);

        // Police bitmap intégrée à GD : aucune fonte n'a besoin d'être livrée.
        $font = 5;
        $characterWidth = imagefontwidth($font);
        $characterHeight = imagefontheight($font);
        $scale = (int) max(1, (int) floor(($size * ($maskable ? 0.34 : 0.46)) / max(1, $characterHeight)));

        $textWidth = $characterWidth * strlen($initials) * $scale;
        $textHeight = $characterHeight * $scale;

        $layer = imagecreatetruecolor(max(1, $characterWidth * strlen($initials)), max(1, $characterHeight));
        if ($layer === false) {
            imagedestroy($image);

            throw new RuntimeException('pwa.error.generation_failed');
        }
        $layerBackground = (int) imagecolorallocate($layer, self::BACKGROUND[0], self::BACKGROUND[1], self::BACKGROUND[2]);
        $layerForeground = (int) imagecolorallocate($layer, 255, 255, 255);
        imagefilledrectangle($layer, 0, 0, imagesx($layer) - 1, imagesy($layer) - 1, $layerBackground);
        imagestring($layer, $font, 0, 0, $initials, $layerForeground);

        imagecopyresized(
            $image,
            $layer,
            (int) (($size - $textWidth) / 2),
            (int) (($size - $textHeight) / 2),
            0,
            0,
            max(1, $textWidth),
            max(1, $textHeight),
            imagesx($layer),
            imagesy($layer)
        );
        imagedestroy($layer);

        $written = imagepng($image, $file, 9);
        imagedestroy($image);

        if ($written === false) {
            throw new RuntimeException('pwa.error.generation_failed');
        }

        @chmod($file, 0o640);
    }

    /**
     * Deux lettres au plus, en ASCII : la police bitmap de GD ne connaît pas
     * les caractères accentués.
     */
    public static function initials(string $label): string
    {
        $normalised = @iconv('UTF-8', 'ASCII//TRANSLIT', $label);
        if ($normalised === false) {
            $normalised = $label;
        }

        $words = preg_split('/[^A-Za-z0-9]+/', $normalised, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $initials = '';
        foreach ($words as $word) {
            $initials .= strtoupper($word[0]);
            if (strlen($initials) === 2) {
                break;
            }
        }

        return $initials === '' ? 'SS' : $initials;
    }
}
