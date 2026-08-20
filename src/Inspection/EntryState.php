<?php

declare(strict_types=1);

namespace SecondStay\Inspection;

/**
 * Constat pour une zone.
 */
enum EntryState: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Anomaly = 'anomaly';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Pending;
    }

    public function isAnomaly(): bool
    {
        return $this === self::Anomaly;
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }

    public function labelKey(): string
    {
        return 'inspection.state.' . $this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Ok => 'text-bg-success',
            self::Anomaly => 'text-bg-danger',
            self::Pending => 'text-bg-secondary',
        };
    }
}
