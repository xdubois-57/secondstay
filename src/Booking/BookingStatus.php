<?php

declare(strict_types=1);

namespace SecondStay\Booking;

/**
 * États principaux d'un séjour (SPECIFICATIONS.md §26).
 *
 * Les sous-états — contrat, paiements, caution, ménage, arrivée, départ —
 * vivent dans leurs propres colonnes : un séjour confirmé dont le contrat
 * n'est pas signé reste confirmé.
 */
enum BookingStatus: string
{
    /**
     * Verrou temporaire posé pendant le parcours de réservation. Il occupe
     * réellement les nuits, sinon deux visiteurs pourraient finaliser le même
     * séjour ; il expire seul.
     */
    case Hold = 'hold';
    case Request = 'request';
    case ToConfirm = 'to_confirm';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refused = 'refused';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Request;
    }

    /**
     * Un état occupant les nuits : il empêche toute autre réservation.
     */
    public function occupiesNights(): bool
    {
        return match ($this) {
            self::Cancelled, self::Refused => false,
            default => true,
        };
    }

    /**
     * Un état encore modifiable par le client.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Hold, self::Request, self::ToConfirm => true,
            default => false,
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::Refused => true,
            default => false,
        };
    }

    /**
     * Transitions autorisées. Toute autre transition est refusée : le
     * workflow ne dépend jamais de ce qu'un formulaire envoie.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Hold => [self::Request, self::Cancelled],
            self::Request => [self::ToConfirm, self::Confirmed, self::Refused, self::Cancelled],
            self::ToConfirm => [self::Confirmed, self::Refused, self::Cancelled],
            self::Confirmed => [self::InProgress, self::Completed, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::Refused => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function labelKey(): string
    {
        return 'booking.status.' . $this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Hold => 'text-bg-secondary',
            self::Request, self::ToConfirm => 'text-bg-warning',
            self::Confirmed, self::InProgress => 'text-bg-success',
            self::Completed => 'text-bg-primary',
            self::Cancelled, self::Refused => 'text-bg-danger',
        };
    }
}
