<?php

declare(strict_types=1);

namespace SecondStay\Calendar;

/**
 * Portée d'un flux ICS (SPECIFICATIONS.md §51).
 *
 * Chaque portée montre exactement ce dont son destinataire a besoin, et rien
 * de plus : un flux abonné dans un agenda tiers finit souvent par être
 * partagé sans y penser.
 */
enum CalendarScope: string
{
    /** Tous les séjours, avec voyageur, montants et état. */
    case Admin = 'admin';

    /** Tous les séjours à venir, sans aucune donnée financière. */
    case Manager = 'manager';

    /** Un seul séjour, celui du voyageur, avec le contact du responsable. */
    case Customer = 'customer';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Customer;
    }

    /**
     * La portée expose-t-elle des montants ?
     */
    public function showsAmounts(): bool
    {
        return $this === self::Admin;
    }

    /**
     * La portée expose-t-elle l'identité et les coordonnées du voyageur ?
     */
    public function showsGuest(): bool
    {
        return $this === self::Admin || $this === self::Manager;
    }

    /**
     * La portée est-elle limitée à un seul séjour ?
     */
    public function isSingleBooking(): bool
    {
        return $this === self::Customer;
    }

    public function labelKey(): string
    {
        return 'calendar.scope.' . $this->value;
    }
}
