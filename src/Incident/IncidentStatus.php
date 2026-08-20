<?php

declare(strict_types=1);

namespace SecondStay\Incident;

/**
 * Cycle de vie d'un incident : signalé → pris en charge → résolu
 * (SPECIFICATIONS.md §54).
 *
 * Les transitions sont explicites plutôt que libres : un incident ne peut pas
 * revenir de « résolu » à « signalé » par un simple champ de formulaire, mais
 * il peut être **rouvert**, ce qui est une action nommée et donc tracée.
 */
enum IncidentStatus: string
{
    case Reported = 'reported';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Reported;
    }

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }

    /**
     * Transitions permises depuis cet état.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Reported => [self::Acknowledged, self::Resolved],
            self::Acknowledged => [self::Resolved],
            // Rouvrir revient à reprendre l'incident en charge : il n'y a pas
            // de raison de le renvoyer à l'état « jamais lu ».
            self::Resolved => [self::Acknowledged],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function labelKey(): string
    {
        return 'incident.status.' . $this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Reported => 'text-bg-danger',
            self::Acknowledged => 'text-bg-warning',
            self::Resolved => 'text-bg-success',
        };
    }
}
