<?php

declare(strict_types=1);

namespace SecondStay\Support;

/**
 * Normalisation d'identifiants lisibles (slugs, catégories).
 *
 * Les identifiants restent ASCII : ils apparaissent dans les URLs, les noms de
 * fichiers et les filtres, où les accents provoquent des encodages instables.
 */
final class Slugger
{
    public static function slug(string $value, int $maxLength = 120): string
    {
        $normalised = self::transliterate($value);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $normalised) ?? '';
        $slug = trim($slug, '-');

        return mb_substr($slug, 0, $maxLength);
    }

    /**
     * La table d'`Ascii` sert de référence, pas de repli : un slug entre dans
     * des URLs et des noms de fichiers, et doit donc être le même quel que
     * soit l'hébergement — avec ou sans `intl`, glibc ou libiconv.
     */
    private static function transliterate(string $value): string
    {
        return mb_strtolower(Ascii::fold(trim($value)));
    }
}
