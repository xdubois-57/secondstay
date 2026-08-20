<?php

declare(strict_types=1);

namespace SecondStay\Payment;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Pending;
    }

    public function isSettled(): bool
    {
        return match ($this) {
            self::Paid, self::Refunded, self::PartiallyRefunded => true,
            default => false,
        };
    }

    /**
     * Un état définitif ne peut plus changer : un webhook en retard qui
     * annoncerait le contraire est ignoré.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Refunded => true,
            default => false,
        };
    }

    /**
     * Un état constaté chez le fournisseur peut-il en remplacer un autre ?
     *
     * Les notifications arrivent dans le désordre (SPECIFICATIONS.md §34) :
     * une notification ancienne ne doit jamais défaire un encaissement déjà
     * constaté. Un paiement encaissé ne se défait que par un remboursement,
     * qui passe par `PaymentService::refund()` et non par cette voie.
     */
    public function canBeReplacedBy(self $target): bool
    {
        if ($this === $target || $this->isFinal()) {
            return false;
        }

        return !$this->isSettled() || $target->isSettled();
    }

    public function labelKey(): string
    {
        return 'payment.status.' . $this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Paid => 'text-bg-success',
            self::Authorized, self::Pending => 'text-bg-warning',
            self::Failed => 'text-bg-danger',
            self::Refunded, self::PartiallyRefunded => 'text-bg-primary',
            self::Cancelled => 'text-bg-secondary',
        };
    }
}
