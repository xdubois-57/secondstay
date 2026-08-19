<?php

declare(strict_types=1);

namespace SecondStay\Security;

use SecondStay\Core\Session;

/**
 * Protection CSRF pour toute mutation navigateur (SECURITY.md §6).
 *
 * Le jeton vit dans la session ; il est renouvelé à chaque authentification et
 * comparé en temps constant. Il passe par l'API de session plutôt que par une
 * référence brute : c'est ce qui permet à la session de ne s'ouvrir
 * réellement qu'au premier besoin.
 */
final class Csrf
{
    public const SESSION_KEY = '_csrf_token';
    public const FIELD = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $existing = $this->session->string(self::SESSION_KEY);
        if ($existing !== '') {
            return $existing;
        }

        $token = Tokens::generate();
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function rotate(): string
    {
        $this->session->remove(self::SESSION_KEY);

        return $this->token();
    }

    /**
     * Vrai uniquement si un jeton a déjà été émis pour cette session.
     */
    public function hasToken(): bool
    {
        return $this->session->string(self::SESSION_KEY) !== '';
    }

    public function isValid(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        $expected = $this->session->string(self::SESSION_KEY);
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }
}
