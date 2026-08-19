<?php

declare(strict_types=1);

namespace SecondStay\Push;

use RuntimeException;

/**
 * Chiffrement de charge utile Web Push : `aes128gcm` (RFC 8188) avec accord de
 * clés ECDH P-256 et dérivation HKDF telle que définie par la RFC 8291.
 *
 * Le serveur ne peut pas relire un message qu'il a émis : la clé de contenu
 * dépend du secret d'authentification propre à l'abonnement.
 */
final class PushEncryption
{
    public const RECORD_SIZE = 4096;

    /**
     * Préfixe DER d'un `SubjectPublicKeyInfo` EC P-256 : la seule partie
     * variable est le point non compressé de 65 octets qui le suit.
     */
    private const P256_SPKI_PREFIX =
        "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
        . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    /**
     * Chiffre une charge utile pour un abonnement donné.
     *
     * @param string $userPublicKey point non compressé (65 octets)
     * @param string $authSecret    secret d'authentification (16 octets)
     * @param string $salt          16 octets ; injectable pour les tests
     * @param array{public: string, private: string}|null $serverKeys clés éphémères base64url
     */
    public function encrypt(
        string $payload,
        string $userPublicKey,
        string $authSecret,
        ?string $salt = null,
        ?array $serverKeys = null,
    ): string {
        if (strlen($userPublicKey) !== 65 || $userPublicKey[0] !== "\x04") {
            throw new RuntimeException('push.error.invalid_subscription_key');
        }
        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('push.error.invalid_subscription_key');
        }

        $salt ??= random_bytes(16);
        if (strlen($salt) !== 16) {
            throw new RuntimeException('push.error.invalid_salt');
        }

        $keys = $serverKeys ?? Vapid::generateKeyPair();
        $serverPublic = Base64Url::decode($keys['public']);
        $serverPrivate = Base64Url::decode($keys['private']);

        $sharedSecret = $this->deriveSharedSecret($serverPrivate, $serverPublic, $userPublicKey);

        // RFC 8291 §3.3 : le secret d'authentification sert de sel pour la
        // première extraction, l'accord ECDH de matériau initial.
        $ikm = self::hkdf(
            $authSecret,
            $sharedSecret,
            "WebPush: info\x00" . $userPublicKey . $serverPublic,
            32
        );

        $contentEncryptionKey = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

        // 0x02 marque le dernier (et unique) enregistrement.
        $plaintext = $payload . "\x02";
        if (strlen($plaintext) + 16 > self::RECORD_SIZE) {
            throw new RuntimeException('push.error.payload_too_large');
        }

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $contentEncryptionKey, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('push.error.encryption_failed');
        }

        return $salt
            . pack('N', self::RECORD_SIZE)
            . chr(strlen($serverPublic))
            . $serverPublic
            . $ciphertext
            . $tag;
    }

    /**
     * HKDF (RFC 5869) : extraction puis expansion sur une seule itération,
     * suffisante pour les longueurs utilisées ici.
     */
    public static function hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        if ($length > 32) {
            throw new RuntimeException('push.error.encryption_failed');
        }

        $pseudoRandomKey = hash_hmac('sha256', $ikm, $salt, true);

        return substr(hash_hmac('sha256', $info . "\x01", $pseudoRandomKey, true), 0, $length);
    }

    public static function publicKeyPem(string $point): string
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('push.error.invalid_subscription_key');
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(self::P256_SPKI_PREFIX . $point), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function deriveSharedSecret(string $serverPrivate, string $serverPublic, string $userPublicKey): string
    {
        $private = openssl_pkey_get_private(Vapid::privateKeyPem($serverPrivate, $serverPublic));
        if ($private === false) {
            throw new RuntimeException('push.error.invalid_key');
        }

        $peer = openssl_pkey_get_public(self::publicKeyPem($userPublicKey));
        if ($peer === false) {
            throw new RuntimeException('push.error.invalid_subscription_key');
        }

        $secret = openssl_pkey_derive($peer, $private, 32);
        if ($secret === false || strlen($secret) !== 32) {
            throw new RuntimeException('push.error.encryption_failed');
        }

        return $secret;
    }
}
