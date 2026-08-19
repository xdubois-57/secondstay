<?php

declare(strict_types=1);

namespace SecondStay\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Chiffrement authentifié centralisé (SECURITY.md §10).
 *
 * - AEAD XChaCha20-Poly1305 via libsodium ;
 * - format versionné et préfixé par l'identifiant de clé, ce qui permet la
 *   rotation sans réécriture immédiate de toutes les valeurs ;
 * - les mots de passe ne passent JAMAIS par ce service (hash uniquement).
 */
final class Encryptor
{
    public const PREFIX = 'ss1';

    /** @var array<string, string> identifiant de clé => clé binaire */
    private array $keys = [];

    private string $activeKeyId;

    /**
     * @param array<string, string> $keys identifiant => clé hexadécimale (64 caractères)
     */
    public function __construct(
        #[SensitiveParameter] array $keys,
        string $activeKeyId,
    ) {
        if ($keys === []) {
            throw new RuntimeException('Aucune clé de chiffrement configurée.');
        }

        foreach ($keys as $id => $hex) {
            if (preg_match('/^[a-z0-9_-]{1,32}$/i', (string) $id) !== 1) {
                throw new RuntimeException('Identifiant de clé invalide.');
            }
            $binary = self::decodeKey($hex);
            $this->keys[(string) $id] = $binary;
        }

        if (!isset($this->keys[$activeKeyId])) {
            throw new RuntimeException('La clé active est absente du trousseau.');
        }

        $this->activeKeyId = $activeKeyId;
    }

    public static function fromSingleKey(#[SensitiveParameter] string $hexKey): self
    {
        return new self(['k1' => $hexKey], 'k1');
    }

    public static function generateKey(): string
    {
        return bin2hex(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    /**
     * @return list<string>
     */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }

    public function encrypt(#[SensitiveParameter] string $plaintext, string $context = ''): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $context,
            $nonce,
            $this->keys[$this->activeKeyId]
        );

        return implode('.', [
            self::PREFIX,
            $this->activeKeyId,
            self::base64UrlEncode($nonce),
            self::base64UrlEncode($cipher),
        ]);
    }

    public function decrypt(string $payload, string $context = ''): string
    {
        $parts = explode('.', $payload);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            throw new RuntimeException('Charge chiffrée illisible.');
        }

        [, $keyId, $nonceEncoded, $cipherEncoded] = $parts;
        if (!isset($this->keys[$keyId])) {
            throw new RuntimeException('Clé de déchiffrement inconnue : ' . $keyId);
        }

        $nonce = self::base64UrlDecode($nonceEncoded);
        $cipher = self::base64UrlDecode($cipherEncoded);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipher,
            $context,
            $nonce,
            $this->keys[$keyId]
        );

        if ($plaintext === false) {
            throw new RuntimeException('Authentification du message chiffré échouée.');
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX . '.');
    }

    public function keyIdOf(string $payload): ?string
    {
        $parts = explode('.', $payload);

        return count($parts) === 4 && $parts[0] === self::PREFIX ? $parts[1] : null;
    }

    /**
     * Rechiffre une valeur avec la clé active (rotation de clé).
     */
    public function rotate(string $payload, string $context = ''): string
    {
        return $this->encrypt($this->decrypt($payload, $context), $context);
    }

    /**
     * Masque une valeur sensible pour l'affichage (SECURITY.md §11) : un secret
     * n'est jamais réaffiché intégralement.
     */
    public static function mask(string $value): string
    {
        $length = mb_strlen($value);
        if ($length === 0) {
            return '';
        }
        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - 4) . mb_substr($value, -4);
    }

    private static function decodeKey(#[SensitiveParameter] string $hex): string
    {
        $binary = @hex2bin(trim($hex));
        if ($binary === false || strlen($binary) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('Clé de chiffrement invalide : 64 caractères hexadécimaux attendus.');
        }

        return $binary;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Charge chiffrée illisible.');
        }

        return $decoded;
    }
}
