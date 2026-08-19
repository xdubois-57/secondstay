<?php

declare(strict_types=1);

namespace SecondStay\I18n;

/**
 * Langues de premier rang du produit. Aucune fonctionnalite utilisateur ne
 * doit etre livree dans une seule langue.
 */
final class Locales
{
    public const FR = 'fr';
    public const EN = 'en';
    public const NL = 'nl';
    public const DE = 'de';

    /** @var list<string> */
    public const ALL = [self::FR, self::EN, self::NL, self::DE];

    public const FALLBACK = self::FR;

    /** @var array<string, string> */
    private const NATIVE_NAMES = [
        self::FR => 'Francais',
        self::EN => 'English',
        self::NL => 'Nederlands',
        self::DE => 'Deutsch',
    ];

    /** @var array<string, string> */
    private const ICU = [
        self::FR => 'fr_FR',
        self::EN => 'en_GB',
        self::NL => 'nl_NL',
        self::DE => 'de_DE',
    ];

    public static function isSupported(string $locale): bool
    {
        return in_array(strtolower($locale), self::ALL, true);
    }

    public static function normalise(string $locale): ?string
    {
        $value = strtolower(trim($locale));
        if ($value === '') {
            return null;
        }

        $value = str_replace('_', '-', $value);
        $primary = explode('-', $value)[0];

        return self::isSupported($primary) ? $primary : null;
    }

    public static function nativeName(string $locale): string
    {
        return self::NATIVE_NAMES[$locale] ?? $locale;
    }

    public static function icu(string $locale): string
    {
        return self::ICU[$locale] ?? 'fr_FR';
    }
}
