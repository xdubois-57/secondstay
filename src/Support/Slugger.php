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

    private static function transliterate(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        // La translittération ICU gère correctement œ, ß, ij, les diacritiques…
        $transliterated = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $lower);
        if (is_string($transliterated) && $transliterated !== '') {
            return $transliterated;
        }

        $fallback = @iconv('UTF-8', 'ASCII//TRANSLIT', $lower);

        return is_string($fallback) && $fallback !== '' ? mb_strtolower($fallback) : $lower;
    }
}
