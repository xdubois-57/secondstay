<?php

declare(strict_types=1);

namespace SecondStay\Push;

use InvalidArgumentException;

/**
 * Encodage base64url sans remplissage, tel qu'utilisé par VAPID (RFC 8292),
 * le chiffrement de charge utile (RFC 8291) et les JWT (RFC 7515).
 */
final class Base64Url
{
    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $encoded) !== 1) {
            throw new InvalidArgumentException('push.error.invalid_encoding');
        }

        $padded = strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            throw new InvalidArgumentException('push.error.invalid_encoding');
        }

        return $decoded;
    }
}
