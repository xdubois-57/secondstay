<?php

declare(strict_types=1);

namespace SecondStay\Incident;

/**
 * Urgence d'un incident (SPECIFICATIONS.md §54).
 *
 * Trois niveaux suffisent : au-delà, personne ne fait plus la différence au
 * moment de trier. `Urgent` porte une conséquence réelle — c'est le seul
 * niveau qui déclenche une notification immédiate au responsable.
 */
enum IncidentSeverity: string
{
    case Low = 'low';
    case Normal = 'normal';
    case Urgent = 'urgent';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Normal;
    }

    public function isUrgent(): bool
    {
        return $this === self::Urgent;
    }

    public function labelKey(): string
    {
        return 'incident.severity.' . $this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Urgent => 'text-bg-danger',
            self::Normal => 'text-bg-warning',
            self::Low => 'text-bg-secondary',
        };
    }

    /**
     * Ordre de tri : le plus urgent d'abord.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::Normal => 1,
            self::Low => 2,
        };
    }
}
