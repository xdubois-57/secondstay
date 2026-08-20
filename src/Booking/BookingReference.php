<?php

declare(strict_types=1);

namespace SecondStay\Booking;

/**
 * Référence lisible d'un séjour.
 *
 * Elle est communiquée par téléphone et recopiée à la main : l'alphabet exclut
 * donc les caractères que l'on confond (0/O, 1/I/L). Elle n'est pas un secret
 * et ne remplace jamais une authentification.
 */
final class BookingReference
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const LENGTH = 8;

    public static function generate(): string
    {
        $reference = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($index = 0; $index < self::LENGTH; $index++) {
            $reference .= self::ALPHABET[random_int(0, $max)];
        }

        // Un tiret au milieu : plus facile à dicter et à relire.
        return substr($reference, 0, 4) . '-' . substr($reference, 4);
    }

    public static function normalise(string $reference): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');

        return strlen($clean) === self::LENGTH
            ? substr($clean, 0, 4) . '-' . substr($clean, 4)
            : $clean;
    }

    public static function isValid(string $reference): bool
    {
        $normalised = self::normalise($reference);

        return preg_match('/^[' . self::ALPHABET . ']{4}-[' . self::ALPHABET . ']{4}$/', $normalised) === 1;
    }
}
