<?php

declare(strict_types=1);

namespace SecondStay\Auth\WebAuthn;

use RuntimeException;

/**
 * Conversion d'une clé publique COSE (RFC 8152) en clé PEM utilisable par
 * OpenSSL.
 *
 * Algorithmes acceptés : ES256 (ECDSA P-256) et RS256 (RSASSA-PKCS1 v1.5),
 * qui couvrent l'ensemble des authentificateurs courants.
 */
final class CoseKey
{
    public const ES256 = -7;
    public const RS256 = -257;

    /** @var list<int> */
    public const SUPPORTED_ALGORITHMS = [self::ES256, self::RS256];

    public function __construct(
        public readonly int $algorithm,
        public readonly string $pem,
    ) {
    }

    /**
     * @param array<int, mixed> $cose
     */
    public static function fromCoseArray(array $cose): self
    {
        $keyType = $cose[1] ?? null;
        $algorithm = $cose[3] ?? null;

        if (!is_int($algorithm) || !in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new RuntimeException('webauthn.error.unsupported_algorithm');
        }

        if ($keyType === 2 && $algorithm === self::ES256) {
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;
            if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new RuntimeException('webauthn.error.invalid_key');
            }

            return new self($algorithm, self::ecPem($x, $y));
        }

        if ($keyType === 3 && $algorithm === self::RS256) {
            $modulus = $cose[-1] ?? null;
            $exponent = $cose[-2] ?? null;
            if (!is_string($modulus) || !is_string($exponent)) {
                throw new RuntimeException('webauthn.error.invalid_key');
            }

            return new self($algorithm, self::rsaPem($modulus, $exponent));
        }

        throw new RuntimeException('webauthn.error.unsupported_algorithm');
    }

    public function opensslAlgorithm(): int
    {
        return OPENSSL_ALGO_SHA256;
    }

    /**
     * Clé EC P-256 : SubjectPublicKeyInfo DER puis PEM.
     */
    private static function ecPem(string $x, string $y): string
    {
        $point = "\x04" . $x . $y;

        $algorithmIdentifier = self::sequence(
            self::oid("\x2a\x86\x48\xce\x3d\x02\x01")        // id-ecPublicKey
            . self::oid("\x2a\x86\x48\xce\x3d\x03\x01\x07")  // prime256v1
        );

        $der = self::sequence($algorithmIdentifier . self::bitString($point));

        return self::pem($der, 'PUBLIC KEY');
    }

    /**
     * Clé RSA : SubjectPublicKeyInfo DER puis PEM.
     */
    private static function rsaPem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = self::sequence(self::integer($modulus) . self::integer($exponent));

        $algorithmIdentifier = self::sequence(
            self::oid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") // rsaEncryption
            . "\x05\x00"                                       // NULL
        );

        $der = self::sequence($algorithmIdentifier . self::bitString($rsaPublicKey));

        return self::pem($der, 'PUBLIC KEY');
    }

    private static function pem(string $der, string $label): string
    {
        return "-----BEGIN " . $label . "-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END " . $label . "-----\n";
    }

    private static function sequence(string $content): string
    {
        return "\x30" . self::length(strlen($content)) . $content;
    }

    private static function oid(string $encoded): string
    {
        return "\x06" . self::length(strlen($encoded)) . $encoded;
    }

    private static function bitString(string $content): string
    {
        $payload = "\x00" . $content;

        return "\x03" . self::length(strlen($payload)) . $payload;
    }

    private static function integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . self::length(strlen($value)) . $value;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
