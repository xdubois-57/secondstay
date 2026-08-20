<?php

declare(strict_types=1);

namespace SecondStay\Booking;

use SecondStay\Pricing\DateRange;

/**
 * Séjour tel qu'il est enregistré.
 *
 * Les montants sont **figés à la réservation** : modifier un tarif plus tard
 * ne réécrit jamais un séjour déjà engagé.
 */
final class Booking
{
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly BookingStatus $status,
        public readonly DateRange $range,
        public readonly int $adults,
        public readonly int $children,
        public readonly int $infants,
        public readonly string $locale,
        public readonly ?int $userId,
        public readonly string $guestEmail,
        public readonly string $guestName,
        public readonly string $guestPhone,
        public readonly string $message,
        public readonly bool $cleaning,
        public readonly string $promoCode,
        public readonly int $accommodationCents,
        public readonly int $cleaningCents,
        public readonly int $discountCents,
        public readonly int $totalCents,
        public readonly int $depositCents,
        public readonly int $securityDepositCents,
        public readonly string $currency,
        public readonly SubStatus $contractStatus,
        public readonly SubStatus $paymentStatus,
        public readonly SubStatus $securityDepositStatus,
        public readonly SubStatus $cleaningStatus,
        public readonly SubStatus $checkinStatus,
        public readonly SubStatus $checkoutStatus,
        public readonly ?string $expiresAt,
        public readonly string $createdAt,
        public readonly ?string $confirmedAt,
        public readonly ?string $cancelledAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['reference'],
            BookingStatus::fromString((string) $row['status']),
            DateRange::fromStrings((string) $row['arrival'], (string) $row['departure']),
            (int) $row['adults'],
            (int) $row['children'],
            (int) $row['infants'],
            (string) $row['locale'],
            $row['user_id'] === null ? null : (int) $row['user_id'],
            (string) $row['guest_email'],
            (string) $row['guest_name'],
            (string) $row['guest_phone'],
            (string) ($row['message'] ?? ''),
            (bool) $row['cleaning'],
            (string) $row['promo_code'],
            (int) $row['accommodation_cents'],
            (int) $row['cleaning_cents'],
            (int) $row['discount_cents'],
            (int) $row['total_cents'],
            (int) $row['deposit_cents'],
            (int) $row['security_deposit_cents'],
            (string) $row['currency'],
            SubStatus::fromString((string) $row['contract_status']),
            SubStatus::fromString((string) $row['payment_status']),
            SubStatus::fromString((string) $row['deposit_status']),
            SubStatus::fromString((string) $row['cleaning_status']),
            SubStatus::fromString((string) $row['checkin_status']),
            SubStatus::fromString((string) $row['checkout_status']),
            $row['expires_at'] === null ? null : (string) $row['expires_at'],
            (string) $row['created_at'],
            $row['confirmed_at'] === null ? null : (string) $row['confirmed_at'],
            $row['cancelled_at'] === null ? null : (string) $row['cancelled_at'],
        );
    }

    public function nights(): int
    {
        return $this->range->nights();
    }

    public function guestCount(): int
    {
        return $this->adults + $this->children;
    }

    public function isExpired(?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return strtotime($this->expiresAt . ' UTC') < ($now ?? time());
    }

    /**
     * Solde restant après l'acompte. Les paiements réels arrivent à
     * l'itération suivante ; la structure, elle, est déjà juste.
     */
    public function balanceCents(): int
    {
        return max(0, $this->totalCents - $this->depositCents);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'arrival' => $this->range->arrivalKey(),
            'departure' => $this->range->departureKey(),
            'nights' => $this->nights(),
            'adults' => $this->adults,
            'children' => $this->children,
            'infants' => $this->infants,
            'locale' => $this->locale,
            'cleaning' => $this->cleaning,
            'promo_code' => $this->promoCode,
            'accommodation_cents' => $this->accommodationCents,
            'cleaning_cents' => $this->cleaningCents,
            'discount_cents' => $this->discountCents,
            'total_cents' => $this->totalCents,
            'deposit_cents' => $this->depositCents,
            'balance_cents' => $this->balanceCents(),
            'security_deposit_cents' => $this->securityDepositCents,
            'currency' => $this->currency,
        ];
    }
}
