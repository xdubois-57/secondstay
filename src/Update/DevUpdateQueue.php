<?php

declare(strict_types=1);

namespace SecondStay\Update;

use RuntimeException;
use SecondStay\Core\Paths;

/**
 * Le commit qu'une notification GitHub a demandé d'installer, en attendant
 * que son archive existe.
 *
 * La notification arrive à l'instant où le commit est poussé ; l'archive,
 * elle, n'existe qu'une fois la CI passée (quelques minutes). Le commit est
 * donc noté ici, et la tâche `dev_update` l'installe dès que son archive est
 * publiée. Une poussée plus récente **remplace** la précédente : seul le
 * dernier commit de la branche vaut la peine d'être installé.
 *
 * Fichiers dans `storage/update/`, comme le reste de l'état des mises à jour :
 * ni la base ni une migration ne sont nécessaires pour qu'une installation de
 * développement suive sa branche.
 */
final class DevUpdateQueue
{
    /**
     * Délai laissé à la CI pour publier l'archive, à compter de la
     * notification. Il couvre la construction (quelques minutes) **et** la
     * cadence du planificateur, qu'un hébergement mutualisé fait souvent
     * passer toutes les dix minutes, parfois toutes les heures.
     */
    public const WAIT_SECONDS = 3600;

    private const PENDING = 'dev-pending.json';
    private const DELIVERY = 'dev-webhook.json';

    /** @var (callable(): int)|null */
    private $clock;

    /**
     * @param (callable(): int)|null $clock horloge, injectable pour les tests
     */
    public function __construct(private readonly Paths $paths, ?callable $clock = null)
    {
        $this->clock = $clock;
    }

    public function enqueue(string $commit, string $branch, string $delivery = ''): void
    {
        $now = $this->now();
        $this->write(self::PENDING, [
            'commit' => strtolower($commit),
            'branch' => $branch,
            'delivery' => $delivery,
            'received_at' => $now,
            'deadline' => $now + self::WAIT_SECONDS,
        ]);
    }

    /**
     * @return array{commit: string, branch: string, delivery: string, received_at: int, deadline: int}|null
     */
    public function pending(): ?array
    {
        $data = $this->read(self::PENDING);
        if ($data === null || !is_string($data['commit'] ?? null) || preg_match('/^[0-9a-f]{40}\z/', $data['commit']) !== 1) {
            return null;
        }

        return [
            'commit' => $data['commit'],
            'branch' => is_string($data['branch'] ?? null) ? $data['branch'] : '',
            'delivery' => is_string($data['delivery'] ?? null) ? $data['delivery'] : '',
            'received_at' => is_int($data['received_at'] ?? null) ? $data['received_at'] : 0,
            'deadline' => is_int($data['deadline'] ?? null) ? $data['deadline'] : 0,
        ];
    }

    public function clear(): void
    {
        @unlink($this->file(self::PENDING));
    }

    /**
     * Mémorise la dernière notification reçue, pour la page des mises à jour :
     * « le webhook est-il configuré ? » se lit à sa date.
     */
    public function recordDelivery(string $event, string $outcome): void
    {
        $this->write(self::DELIVERY, [
            'received_at' => $this->now(),
            'event' => $event,
            'outcome' => $outcome,
        ]);
    }

    /**
     * @return array{received_at: int, event: string, outcome: string}|null
     */
    public function lastDelivery(): ?array
    {
        $data = $this->read(self::DELIVERY);
        if ($data === null || !is_int($data['received_at'] ?? null)) {
            return null;
        }

        return [
            'received_at' => $data['received_at'],
            'event' => is_string($data['event'] ?? null) ? $data['event'] : '',
            'outcome' => is_string($data['outcome'] ?? null) ? $data['outcome'] : '',
        ];
    }

    public function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private function file(string $name): string
    {
        $directory = $this->paths->storage('update');
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Répertoire inaccessible : ' . $directory);
        }

        return $directory . '/' . $name;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $name, array $data): void
    {
        $path = $this->file($name);
        $temporary = $path . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Écriture impossible : ' . $name);
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function read(string $name): ?array
    {
        $path = $this->file($name);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }
}
