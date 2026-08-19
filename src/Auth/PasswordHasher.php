<?php

declare(strict_types=1);

namespace SecondStay\Auth;

use SensitiveParameter;

/**
 * Hachage des mots de passe via l'API PHP recommandée (SECURITY.md §4).
 * Aucun mot de passe n'est jamais stocké de façon réversible.
 */
final class PasswordHasher
{
    public const MIN_LENGTH = 12;

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Évaluation de robustesse indépendante de la langue.
     *
     * @return array{score: int, errors: list<string>}
     */
    public function evaluate(#[SensitiveParameter] string $password): array
    {
        $errors = [];
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $errors[] = 'auth.password.too_short';
        }
        if (preg_match('/\p{Lu}/u', $password) !== 1) {
            $errors[] = 'auth.password.needs_uppercase';
        }
        if (preg_match('/\p{Ll}/u', $password) !== 1) {
            $errors[] = 'auth.password.needs_lowercase';
        }
        if (preg_match('/\d/', $password) !== 1) {
            $errors[] = 'auth.password.needs_digit';
        }

        $score = 0;
        $score += min(40, (int) floor($length * 2.5));
        $score += preg_match('/\p{Lu}/u', $password) === 1 ? 15 : 0;
        $score += preg_match('/\p{Ll}/u', $password) === 1 ? 15 : 0;
        $score += preg_match('/\d/', $password) === 1 ? 15 : 0;
        $score += preg_match('/[^\p{L}\d]/u', $password) === 1 ? 15 : 0;

        $unique = count(array_unique(mb_str_split($password)));
        if ($unique < 5) {
            $score = (int) round($score / 2);
            $errors[] = 'auth.password.too_repetitive';
        }

        return ['score' => min(100, $score), 'errors' => $errors];
    }
}
