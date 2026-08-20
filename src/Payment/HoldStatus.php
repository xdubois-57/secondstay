<?php

declare(strict_types=1);

namespace SecondStay\Payment;

/**
 * Cycle de vie d'une caution (SPECIFICATIONS.md §32).
 *
 * La caution est encaissée puis remboursée plutôt que préautorisée : une
 * préautorisation longue n'est pas tenable sur la durée d'un séjour.
 */
enum HoldStatus: string
{
    case None = 'none';
    case ToPay = 'to_pay';
    case Received = 'received';
    case ToReturn = 'to_return';
    case Returned = 'returned';
    case PartiallyRetained = 'partially_retained';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::None;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::None => [self::ToPay],
            self::ToPay => [self::Received, self::None],
            self::Received => [self::ToReturn],
            self::ToReturn => [self::Returned, self::PartiallyRetained],
            self::Returned, self::PartiallyRetained => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function labelKey(): string
    {
        return 'payment.hold.' . $this->value;
    }
}
