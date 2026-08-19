<?php

declare(strict_types=1);

namespace SecondStay\Maintenance;

use RuntimeException;

/**
 * Mode maintenance.
 *
 * L'état vit dans un fichier de `storage/` afin de rester disponible même si la
 * base de données est indisponible (mise à jour, restauration, migration).
 */
final class MaintenanceMode
{
    public function __construct(private readonly string $flagFile)
    {
    }

    public function isActive(): bool
    {
        // Le cache de statistiques de fichiers peut survivre entre deux
        // requêtes dans un processus persistant : l'état de maintenance doit
        // toujours être lu sur le disque.
        clearstatcache(true, $this->flagFile);

        return is_file($this->flagFile);
    }

    /**
     * @return array{active: bool, reason: string, since: ?string, allow_token: ?string}
     */
    public function state(): array
    {
        if (!$this->isActive()) {
            return ['active' => false, 'reason' => '', 'since' => null, 'allow_token' => null];
        }

        $raw = file_get_contents($this->flagFile);
        /** @var array{reason?: string, since?: string, allow_token?: string} $data */
        $data = $raw === false ? [] : (json_decode($raw, true) ?: []);

        return [
            'active' => true,
            'reason' => (string) ($data['reason'] ?? 'maintenance.reason.generic'),
            'since' => isset($data['since']) ? (string) $data['since'] : null,
            'allow_token' => isset($data['allow_token']) ? (string) $data['allow_token'] : null,
        ];
    }

    /**
     * @param string $reasonKey clé de traduction, jamais un texte figé
     */
    public function enable(string $reasonKey = 'maintenance.reason.generic', ?string $allowToken = null): void
    {
        $directory = dirname($this->flagFile);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible d’activer le mode maintenance.');
        }

        $payload = json_encode([
            'reason' => $reasonKey,
            'since' => gmdate('c'),
            'allow_token' => $allowToken,
        ], JSON_UNESCAPED_UNICODE);

        if (file_put_contents($this->flagFile, (string) $payload, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’activer le mode maintenance.');
        }
    }

    public function disable(): void
    {
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    /**
     * Exécute une opération sensible sous maintenance, puis rétablit l'état
     * initial même en cas d'échec.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function during(string $reasonKey, callable $operation): mixed
    {
        $wasActive = $this->isActive();
        if (!$wasActive) {
            $this->enable($reasonKey);
        }

        try {
            return $operation();
        } finally {
            if (!$wasActive) {
                $this->disable();
            }
        }
    }
}
