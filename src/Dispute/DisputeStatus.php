<?php

declare(strict_types=1);

namespace SecondStay\Dispute;

/**
 * Cycle de vie d'un litige.
 *
 * Ouvert → en discussion → résolu, avec la possibilité de rouvrir. Comme pour
 * un incident, les transitions sont nommées : un litige ne change pas d'état
 * parce qu'un champ de formulaire l'a dit.
 */
enum DisputeStatus: string
{
    case Open = 'open';
    case Discussing = 'discussing';
    case Resolved = 'resolved';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Open;
    }

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Discussing, self::Resolved],
            self::Discussing => [self::Resolved],
            self::Resolved => [self::Discussing],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function labelKey(): string
    {
        return 'dispute.status.' . $this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'text-bg-danger',
            self::Discussing => 'text-bg-warning',
            self::Resolved => 'text-bg-success',
        };
    }
}
