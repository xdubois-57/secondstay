<?php

declare(strict_types=1);

namespace SecondStay\Logging;

/**
 * Aucune donnée secrète ne doit atteindre les journaux (SECURITY.md §17).
 */
final class LogSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'encryption_key', 'private_key', 'vapid', 'csrf', 'session',
        'card', 'iban', 'cvv', 'pin',
    ];

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function sanitize(array $context, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['_truncated' => true];
        }

        $clean = [];
        foreach ($context as $key => $value) {
            $lowered = strtolower((string) $key);
            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $needle) {
                if (str_contains($lowered, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $clean[$key] = '***';
                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $clean[$key] = self::sanitize($value, $depth + 1);
                continue;
            }

            if (is_object($value)) {
                $clean[$key] = $value::class;
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = self::redactPatterns(mb_substr($value, 0, 2000));
                continue;
            }

            /** @var scalar|null $value */
            $clean[$key] = $value;
        }

        return $clean;
    }

    public static function redactPatterns(string $value): string
    {
        $patterns = [
            '/\b(live|test)_[A-Za-z0-9]{16,}\b/' => '***',
            '/\bBearer\s+[A-Za-z0-9._-]{10,}/i' => 'Bearer ***',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '***',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $value);
            if ($result !== null) {
                $value = $result;
            }
        }

        return $value;
    }
}
