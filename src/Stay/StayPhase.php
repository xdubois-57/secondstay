<?php

declare(strict_types=1);

namespace SecondStay\Stay;

use DateTimeImmutable;
use DateTimeZone;
use SecondStay\Booking\Booking;

/**
 * Phase d'un séjour (SPECIFICATIONS.md §45).
 *
 * Elle n'est pas stockée : elle se déduit des dates et du jour courant. Une
 * phase recopiée en base serait fausse dès le lendemain.
 */
enum StayPhase: string
{
    case Before = 'before';
    case Arrival = 'arrival';
    case During = 'during';
    case Departure = 'departure';
    case After = 'after';

    /** Bloc d'information affiché quelle que soit la phase. */
    public const ANY = 'any';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Before;
    }

    /**
     * Phase d'un séjour à une date donnée, dans le fuseau du logement.
     *
     * Le jour d'arrivée et le jour de départ ont leur propre phase : ce sont
     * les deux moments où le voyageur a besoin d'informations différentes de
     * celles du reste du séjour.
     */
    public static function of(Booking $booking, string $timezone = 'Europe/Paris', ?string $today = null): self
    {
        $zone = self::zone($timezone);
        $now = $today === null
            ? new DateTimeImmutable('today', $zone)
            : new DateTimeImmutable($today . ' 00:00:00', $zone);

        $day = $now->format('Y-m-d');
        $arrival = $booking->range->arrival->format('Y-m-d');
        $departure = $booking->range->departure->format('Y-m-d');

        return match (true) {
            $day < $arrival => self::Before,
            $day === $arrival => self::Arrival,
            $day === $departure => self::Departure,
            $day > $departure => self::After,
            default => self::During,
        };
    }

    /**
     * Le séjour est-il en cours, arrivée et départ compris ?
     *
     * C'est cette fenêtre — et elle seule — qui autorise la publication des
     * codes d'accès.
     */
    public function isOnSite(): bool
    {
        return match ($this) {
            self::Arrival, self::During, self::Departure => true,
            default => false,
        };
    }

    public function labelKey(): string
    {
        return 'stay.phase.' . $this->value;
    }

    /**
     * Fuseau du logement, avec repli sûr : un fuseau invalide ne doit pas
     * faire échouer l'affichage du séjour.
     */
    private static function zone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone === '' ? 'UTC' : $timezone);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
