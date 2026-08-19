<?php

declare(strict_types=1);

namespace SecondStay\Security;

/**
 * Protection CSRF pour toute mutation navigateur (SECURITY.md §6).
 *
 * Le jeton vit dans la session PHP ; il est renouvelé à chaque authentification
 * et comparé en temps constant.
 */
final class Csrf
{
    public const SESSION_KEY = '_csrf_token';
    public const FIELD = '_csrf';

    /**
     * @param array<string, mixed> $session référence vers $_SESSION ou équivalent testable
     */
    public function __construct(private array &$session)
    {
    }

    public function token(): string
    {
        $existing = $this->session[self::SESSION_KEY] ?? null;
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = Tokens::generate();
        $this->session[self::SESSION_KEY] = $token;

        return $token;
    }

    public function rotate(): string
    {
        unset($this->session[self::SESSION_KEY]);

        return $this->token();
    }

    public function isValid(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        $expected = $this->session[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }
}
