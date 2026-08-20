<?php

declare(strict_types=1);

namespace SecondStay\Payment;

/**
 * Composants financiers d'un séjour (SPECIFICATIONS.md §29).
 *
 * Chacun est un objet distinct, avec son montant, son échéance et son état :
 * un solde impayé n'empêche pas une caution d'être reçue.
 */
enum PaymentKind: string
{
    case Deposit = 'deposit';
    case Balance = 'balance';
    case SecurityDeposit = 'security_deposit';
    case Cleaning = 'cleaning';
    case TouristTax = 'tourist_tax';
    case Adjustment = 'adjustment';
    case Refund = 'refund';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Adjustment;
    }

    /**
     * Une caution n'est pas un revenu : elle est encaissée puis restituée.
     */
    public function isRevenue(): bool
    {
        return match ($this) {
            self::SecurityDeposit, self::Refund => false,
            default => true,
        };
    }

    /**
     * Le composant dont le paiement confirme la réservation
     * (SPECIFICATIONS.md §30).
     */
    public function confirmsBooking(): bool
    {
        return $this === self::Deposit;
    }

    public function labelKey(): string
    {
        return 'payment.kind.' . $this->value;
    }
}
