<?php

declare(strict_types=1);

namespace SecondStay\Operations;

/**
 * Moment auquel une tâche d'exploitation se rapporte
 * (SPECIFICATIONS.md §49).
 */
enum TaskPhase: string
{
    case Before = 'before';
    case Departure = 'departure';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Before;
    }

    public function labelKey(): string
    {
        return 'operations.phase.' . $this->value;
    }
}
