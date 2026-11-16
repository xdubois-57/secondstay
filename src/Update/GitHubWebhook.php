<?php

declare(strict_types=1);

namespace SecondStay\Update;

/**
 * Lecture d'une notification GitHub (« webhook ») du canal de développement.
 *
 * GitHub n'a pas de session : ce n'est ni un jeton CSRF ni un compte qui
 * l'authentifie, mais la signature HMAC-SHA256 du corps brut avec le secret
 * partagé (`X-Hub-Signature-256: sha256=<hex>`), comparée en temps constant.
 * Rien du corps n'est lu avant que cette signature soit vérifiée.
 *
 * Même vérifié, le corps ne décide pas de ce qui est installé : il désigne un
 * commit, et l'installation ira chercher l'archive de **ce** commit dans les
 * releases du dépôt que nomment les réglages, avec son empreinte
 * (`DevUpdater`). Un dépôt ou une branche autres que ceux des réglages sont
 * ignorés.
 */
final class GitHubWebhook
{
    public const SIGNATURE_HEADER = 'X-Hub-Signature-256';
    public const EVENT_HEADER = 'X-GitHub-Event';
    public const DELIVERY_HEADER = 'X-GitHub-Delivery';

    /** Longueur du secret généré, en octets (64 caractères hexadécimaux). */
    public const SECRET_BYTES = 32;

    /** Longueur minimale d'un secret saisi à la main. */
    public const MINIMUM_SECRET_LENGTH = 32;

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(self::SECRET_BYTES));
    }

    public static function isSignatureValid(string $secret, string $body, ?string $header): bool
    {
        if (strlen($secret) < self::MINIMUM_SECRET_LENGTH || $header === null) {
            return false;
        }

        $header = trim($header);
        if (preg_match('/^sha256=([0-9a-f]{64})\z/i', $header, $matches) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), strtolower($matches[1]));
    }

    /**
     * Commit à installer pour une poussée, ou null si cette poussée ne
     * concerne pas l'installation : autre dépôt, autre branche, suppression de
     * branche, corps illisible.
     *
     * @param array<mixed> $payload
     */
    public static function pushedCommit(array $payload, string $repository, string $branch): ?string
    {
        $fullName = $payload['repository']['full_name'] ?? null;
        if (!is_string($fullName) || strcasecmp($fullName, $repository) !== 0) {
            return null;
        }

        if (($payload['ref'] ?? null) !== 'refs/heads/' . $branch) {
            return null;
        }

        // Une branche supprimée est une poussée dont `after` ne vaut que des
        // zéros : il n'y a rien à installer.
        if (($payload['deleted'] ?? false) === true) {
            return null;
        }

        $after = $payload['after'] ?? null;
        if (!is_string($after) || preg_match('/^[0-9a-f]{40}\z/', $after) !== 1 || trim($after, '0') === '') {
            return null;
        }

        return $after;
    }
}
