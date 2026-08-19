<?php

declare(strict_types=1);

namespace SecondStay\Security;

/**
 * Génération et comparaison de jetons porteurs (SECURITY.md §15).
 */
final class Tokens
{
    public const DEFAULT_BYTES = 32;

    public static function generate(int $bytes = self::DEFAULT_BYTES): string
    {
        return rtrim(strtr(base64_encode(self::randomBytes($bytes)), '+/', '-_'), '=');
    }

    public static function generateHex(int $bytes = self::DEFAULT_BYTES): string
    {
        return bin2hex(self::randomBytes($bytes));
    }

    private static function randomBytes(int $bytes): string
    {
        return random_bytes(max(16, $bytes));
    }

    /**
     * Les jetons sont stockés hachés : une fuite de base ne donne pas d'accès.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function equals(string $known, string $provided): bool
    {
        return hash_equals($known, $provided);
    }

    /**
     * Code court lisible pour une référence de réservation.
     */
    public static function reference(string $prefix = ''): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $code = substr($code, 0, 4) . '-' . substr($code, 4);

        return $prefix === '' ? $code : $prefix . '-' . $code;
    }
}
