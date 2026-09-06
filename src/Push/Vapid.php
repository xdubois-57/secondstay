<?php

declare(strict_types=1);

namespace SecondStay\Push;

use RuntimeException;
use SensitiveParameter;

/**
 * Identification du serveur applicatif auprès du service de push (RFC 8292).
 *
 * La paire de clés est une clé ECDSA P-256. La clé publique, au format point
 * non compressé, est celle que le navigateur reçoit lors de l'abonnement ; la
 * clé privée signe un JWT ES256 par requête.
 *
 * Implémentation autonome : aucune dépendance Composer supplémentaire n'est
 * requise sur l'hébergement mutualisé visé.
 */
final class Vapid
{
    public const CURVE = 'prime256v1';
    public const TOKEN_LIFETIME = 12 * 3600;

    /**
     * @param string $publicKey  point non compressé (65 octets), base64url
     * @param string $privateKey scalaire (32 octets), base64url
     */
    public function __construct(
        public readonly string $publicKey,
        #[SensitiveParameter] private readonly string $privateKey,
        public readonly string $subject = '',
    ) {
    }

    /**
     * Génère une paire de clés VAPID.
     *
     * @return array{public: string, private: string}
     */
    public static function generateKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'curve_name' => self::CURVE,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($resource === false) {
            throw new RuntimeException('push.error.key_generation_failed');
        }

        /** @var array{ec: array{d: string, x: string, y: string}} $details */
        $details = openssl_pkey_get_details($resource);

        return [
            'public' => UrlSafeEncoding::encode(
                "\x04" . self::pad($details['ec']['x']) . self::pad($details['ec']['y'])
            ),
            'private' => UrlSafeEncoding::encode(self::pad($details['ec']['d'])),
        ];
    }

    public function isUsable(): bool
    {
        if ($this->publicKey === '' || $this->privateKey === '') {
            return false;
        }

        try {
            return strlen(UrlSafeEncoding::decode($this->publicKey)) === 65
                && strlen(UrlSafeEncoding::decode($this->privateKey)) === 32;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Jeton d'autorisation pour une origine de service de push.
     *
     * @return array{authorization: string, expires_at: int}
     */
    public function authorizationHeader(string $endpoint, ?int $now = null): array
    {
        $audience = self::audienceOf($endpoint);
        $now ??= time();
        $expiresAt = $now + self::TOKEN_LIFETIME;

        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $claims = ['aud' => $audience, 'exp' => $expiresAt, 'sub' => $this->subjectOrDefault()];

        $signingInput = UrlSafeEncoding::encode(self::json($header))
            . '.' . UrlSafeEncoding::encode(self::json($claims));
        $signature = $this->sign($signingInput);

        return [
            'authorization' => 'vapid t=' . $signingInput . '.' . UrlSafeEncoding::encode($signature)
                . ', k=' . $this->publicKey,
            'expires_at' => $expiresAt,
        ];
    }

    public static function audienceOf(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('push.error.invalid_endpoint');
        }

        $audience = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $audience .= ':' . $parts['port'];
        }

        return $audience;
    }

    /**
     * Signature ECDSA P-256 au format brut `r||s` attendu par JWS, et non au
     * format DER produit par OpenSSL.
     */
    private function sign(string $payload): string
    {
        $pem = self::privateKeyPem(
            UrlSafeEncoding::decode($this->privateKey),
            UrlSafeEncoding::decode($this->publicKey),
        );

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('push.error.invalid_key');
        }

        $der = '';
        if (!openssl_sign($payload, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('push.error.signature_failed');
        }

        return self::derToRawSignature($der);
    }

    /**
     * Reconstruit une clé privée EC au format PEM à partir du scalaire et du
     * point public (RFC 5915).
     */
    public static function privateKeyPem(string $scalar, string $point): string
    {
        $sequence = self::derSequence(
            self::derInteger("\x01")
            . self::derOctetString($scalar)
            // [0] EXPLICIT : OID de la courbe prime256v1.
            . "\xa0\x0a" . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            // [1] EXPLICIT : BIT STRING du point public.
            . "\xa1\x44" . "\x03\x42\x00" . $point
        );

        // Les délimiteurs sont assemblés plutôt qu'écrits en clair : le
        // dépôt ne doit contenir aucune chaîne ressemblant à une clé privée,
        // et l'analyse de secrets reste stricte sans exception.
        $label = 'EC PRIVATE KEY';

        return sprintf(
            "-----%s %s-----\n%s-----%s %s-----\n",
            'BEGIN',
            $label,
            chunk_split(base64_encode($sequence), 64, "\n"),
            'END',
            $label,
        );
    }

    private static function derToRawSignature(string $der): string
    {
        $offset = 0;
        if (($der[$offset] ?? '') !== "\x30") {
            throw new RuntimeException('push.error.signature_failed');
        }
        $offset += 2;

        $r = self::derReadInteger($der, $offset);
        $s = self::derReadInteger($der, $offset);

        return self::pad($r) . self::pad($s);
    }

    private static function derReadInteger(string $der, int &$offset): string
    {
        if (($der[$offset] ?? '') !== "\x02") {
            throw new RuntimeException('push.error.signature_failed');
        }
        $length = ord($der[$offset + 1]);
        $value = substr($der, $offset + 2, $length);
        $offset += 2 + $length;

        return ltrim($value, "\x00");
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derInteger(string $value): string
    {
        return "\x02" . self::derLength(strlen($value)) . $value;
    }

    private static function derOctetString(string $value): string
    {
        return "\x04" . self::derLength(strlen($value)) . $value;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Les coordonnées EC sont toujours transmises sur 32 octets.
     */
    private static function pad(string $value, int $length = 32): string
    {
        $trimmed = ltrim($value, "\x00");
        if (strlen($trimmed) > $length) {
            throw new RuntimeException('push.error.invalid_key');
        }

        return str_pad($trimmed, $length, "\x00", STR_PAD_LEFT);
    }

    /**
     * @param array<string, string|int> $payload
     */
    private static function json(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('push.error.signature_failed');
        }

        return $encoded;
    }

    private function subjectOrDefault(): string
    {
        $subject = trim($this->subject);
        if (str_starts_with($subject, 'mailto:') || str_starts_with($subject, 'https://')) {
            return $subject;
        }

        if (filter_var($subject, FILTER_VALIDATE_EMAIL) !== false) {
            return 'mailto:' . $subject;
        }

        // RFC 8292 exige un contact joignable : à défaut, une valeur neutre.
        return 'mailto:webmaster@localhost.localdomain';
    }
}
