<?php

declare(strict_types=1);

namespace SecondStay\Inspection;

/**
 * Moment d'un état des lieux (SPECIFICATIONS.md §53).
 *
 * Les deux n'ont pas les mêmes exigences : à l'arrivée, le voyageur signale
 * ce qui ne va pas et peut ne rien dire si tout est conforme ; au départ, les
 * photos des zones requises sont obligatoires.
 */
enum InspectionKind: string
{
    case Checkin = 'checkin';
    case Checkout = 'checkout';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Checkin;
    }

    /**
     * Les photos des zones requises sont-elles obligatoires ?
     */
    public function requiresPhotos(): bool
    {
        return $this === self::Checkout;
    }

    public function labelKey(): string
    {
        return 'inspection.kind.' . $this->value;
    }

    /**
     * Sous-état du séjour que cet état des lieux fait avancer.
     */
    public function bookingColumn(): string
    {
        return $this === self::Checkin ? 'checkin_status' : 'checkout_status';
    }
}
