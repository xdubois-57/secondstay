<?php

declare(strict_types=1);

namespace SecondStay\Booking;

/**
 * Sous-états d'un séjour (SPECIFICATIONS.md §26).
 *
 * Ils sont volontairement partagés par les six dimensions — contrat,
 * paiements, caution, ménage, arrivée, départ : chacune progresse
 * indépendamment de l'état principal, et les itérations suivantes les
 * alimentent sans changer le modèle.
 */
enum SubStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Partial = 'partial';
    case Done = 'done';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::None;
    }

    public function labelKey(): string
    {
        return 'booking.substatus.' . $this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Done => 'text-bg-success',
            self::Partial, self::Pending => 'text-bg-warning',
            self::Failed => 'text-bg-danger',
            default => 'text-bg-secondary',
        };
    }
}
