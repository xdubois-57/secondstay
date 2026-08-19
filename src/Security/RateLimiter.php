<?php

declare(strict_types=1);

namespace SecondStay\Security;

use SecondStay\Database\Database;

/**
 * Limitation de débit simple par fenêtre glissante (SECURITY.md §4).
 * Utilisée pour login, signup, reset et endpoints sensibles.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Database $database,
        private readonly int $windowSeconds = 900,
    ) {
    }

    /**
     * @return array{allowed: bool, hits: int, retry_after: int}
     */
    public function hit(string $bucket, int $maxAttempts): array
    {
        $bucket = mb_substr($bucket, 0, 190);
        $now = time();

        $row = $this->database->fetchOne(
            'SELECT `window_start`, `hits` FROM `rate_limit` WHERE `bucket` = :bucket',
            ['bucket' => $bucket]
        );

        $windowStart = $now;
        $hits = 0;

        if ($row !== null) {
            $storedStart = strtotime((string) $row['window_start'] . ' UTC');
            if ($storedStart !== false && ($now - $storedStart) < $this->windowSeconds) {
                $windowStart = $storedStart;
                $hits = (int) $row['hits'];
            }
        }

        $hits++;

        $this->database->execute(
            'INSERT INTO `rate_limit` (`bucket`, `window_start`, `hits`) VALUES (:bucket, :window_start, :hits) '
            . 'ON DUPLICATE KEY UPDATE `window_start` = VALUES(`window_start`), `hits` = VALUES(`hits`)',
            [
                'bucket' => $bucket,
                'window_start' => gmdate('Y-m-d H:i:s', $windowStart),
                'hits' => $hits,
            ]
        );

        $retryAfter = max(0, $this->windowSeconds - ($now - $windowStart));

        return [
            'allowed' => $hits <= $maxAttempts,
            'hits' => $hits,
            'retry_after' => $hits > $maxAttempts ? $retryAfter : 0,
        ];
    }

    public function reset(string $bucket): void
    {
        $this->database->delete('rate_limit', ['bucket' => mb_substr($bucket, 0, 190)]);
    }

    /**
     * Efface toutes les fenêtres en cours.
     *
     * Réservé à une action d'administration explicite : un propriétaire
     * bloqué par ses propres tentatives doit pouvoir se débloquer sans
     * attendre l'expiration de la fenêtre ni toucher à la base.
     */
    public function clearAll(): int
    {
        return $this->database->execute('DELETE FROM `rate_limit`')->rowCount();
    }

    public function purge(): int
    {
        return $this->database->execute(
            'DELETE FROM `rate_limit` WHERE `window_start` < :threshold',
            ['threshold' => gmdate('Y-m-d H:i:s', time() - ($this->windowSeconds * 4))]
        )->rowCount();
    }
}
