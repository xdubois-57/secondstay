<?php

declare(strict_types=1);

namespace SecondStay\Booking;

/**
 * Code promotionnel (SPECIFICATIONS.md §23).
 *
 * La remise porte sur l'hébergement seul : le ménage et la caution ne sont
 * pas des marges commerciales.
 */
final class PromoCode
{
    public const KIND_FIXED = 'fixed';
    public const KIND_PERCENT = 'percent';

    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $kind,
        public readonly int $value,
        public readonly bool $isActive,
        public readonly ?string $startsOn,
        public readonly ?string $endsOn,
        public readonly ?int $maxUses,
        public readonly int $usedCount,
        public readonly string $label = '',
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['kind'],
            (int) $row['value'],
            (bool) $row['is_active'],
            $row['starts_on'] === null ? null : (string) $row['starts_on'],
            $row['ends_on'] === null ? null : (string) $row['ends_on'],
            $row['max_uses'] === null ? null : (int) $row['max_uses'],
            (int) $row['used_count'],
            (string) $row['label'],
        );
    }

    public static function normalise(string $code): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim($code)) ?? '');
    }

    /**
     * Un code utilisable **à la date d'aujourd'hui**, pas à la date du séjour :
     * une promotion s'applique au moment de la réservation.
     *
     * @return string|null clé de traduction du refus, ou null si utilisable
     */
    public function refusalReason(string $today): ?string
    {
        if (!$this->isActive) {
            return 'booking.promo.inactive';
        }
        if ($this->startsOn !== null && $today < $this->startsOn) {
            return 'booking.promo.not_started';
        }
        if ($this->endsOn !== null && $today > $this->endsOn) {
            return 'booking.promo.expired';
        }
        if ($this->maxUses !== null && $this->usedCount >= $this->maxUses) {
            return 'booking.promo.exhausted';
        }

        return null;
    }

    /**
     * Remise appliquée à un montant, bornée à ce montant : une promotion ne
     * peut jamais produire un total négatif.
     */
    public function discountFor(int $accommodationCents): int
    {
        if ($accommodationCents <= 0) {
            return 0;
        }

        $discount = $this->kind === self::KIND_PERCENT
            ? (int) floor($accommodationCents * min(100, $this->value) / 100)
            : $this->value;

        return max(0, min($accommodationCents, $discount));
    }

    public function describe(): string
    {
        return $this->kind === self::KIND_PERCENT ? $this->value . ' %' : (string) $this->value;
    }
}
