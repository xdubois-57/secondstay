<?php

declare(strict_types=1);

namespace SecondStay\Inspection;

/**
 * État d'un état des lieux.
 */
enum InspectionStatus: string
{
    case Open = 'open';
    case Completed = 'completed';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Open;
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    public function labelKey(): string
    {
        return 'inspection.status.' . $this->value;
    }

    public function badgeClass(): string
    {
        return $this === self::Completed ? 'text-bg-success' : 'text-bg-warning';
    }
}
