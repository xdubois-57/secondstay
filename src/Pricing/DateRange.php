<?php

declare(strict_types=1);

namespace SecondStay\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Plage de séjour : arrivée incluse, départ exclu.
 *
 * C'est la convention hôtelière : un séjour du 12 au 19 compte sept nuits,
 * celles du 12 au 18. Toutes les dates sont manipulées en jours civils, sans
 * heure ni fuseau, afin qu'un changement d'heure d'été ne décale jamais un
 * calcul de prix.
 */
final class DateRange
{
    public const MAX_NIGHTS = 366;

    private function __construct(
        public readonly DateTimeImmutable $arrival,
        public readonly DateTimeImmutable $departure,
    ) {
    }

    public static function fromStrings(string $arrival, string $departure): self
    {
        return new self(self::parseDay($arrival), self::parseDay($departure));
    }

    public static function create(DateTimeImmutable $arrival, DateTimeImmutable $departure): self
    {
        return new self(self::normalise($arrival), self::normalise($departure));
    }

    /**
     * Construit une plage à partir de la première et de la **dernière nuit**.
     *
     * C'est la forme dans laquelle les indisponibilités sont stockées : elle
     * évite d'écrire partout « fin + 1 jour ».
     */
    public static function fromNights(string $firstNight, string $lastNight): self
    {
        $first = self::parseDay($firstNight);

        return new self($first, self::parseDay($lastNight)->modify('+1 day'));
    }

    /**
     * Plage valide au sens strict : au moins une nuit et pas plus d'un an.
     */
    public function isValid(): bool
    {
        $nights = $this->nights();

        return $nights >= 1 && $nights <= self::MAX_NIGHTS;
    }

    public function nights(): int
    {
        $difference = $this->arrival->diff($this->departure);

        return $difference->invert === 1 ? -$difference->days : (int) $difference->days;
    }

    /**
     * Nuits du séjour, c'est-à-dire chaque jour d'arrivée jusqu'à la veille du
     * départ.
     *
     * @return list<DateTimeImmutable>
     */
    public function nightsList(): array
    {
        $nights = [];
        $cursor = $this->arrival;

        while ($cursor < $this->departure && count($nights) <= self::MAX_NIGHTS) {
            $nights[] = $cursor;
            $cursor = $cursor->modify('+1 day');
        }

        return $nights;
    }

    /**
     * @return list<string> dates au format ISO
     */
    public function nightKeys(): array
    {
        return array_map(
            static fn (DateTimeImmutable $day): string => $day->format('Y-m-d'),
            $this->nightsList()
        );
    }

    public function arrivalKey(): string
    {
        return $this->arrival->format('Y-m-d');
    }

    public function departureKey(): string
    {
        return $this->departure->format('Y-m-d');
    }

    /**
     * Dernière nuit occupée : le jour du départ est libre pour l'arrivée
     * suivante.
     */
    public function lastNightKey(): string
    {
        return $this->departure->modify('-1 day')->format('Y-m-d');
    }

    public function contains(DateTimeImmutable $day): bool
    {
        $day = self::normalise($day);

        return $day >= $this->arrival && $day < $this->departure;
    }

    /**
     * Deux séjours se chevauchent s'ils partagent au moins une nuit. Un départ
     * le jour d'une arrivée n'est donc pas un chevauchement.
     */
    public function overlaps(self $other): bool
    {
        return $this->arrival < $other->departure && $other->arrival < $this->departure;
    }

    public function equals(self $other): bool
    {
        return $this->arrivalKey() === $other->arrivalKey()
            && $this->departureKey() === $other->departureKey();
    }

    public function __toString(): string
    {
        return $this->arrivalKey() . '→' . $this->departureKey();
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function parseDay(string $value): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($day === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('booking.error.invalid_date');
        }

        return $day;
    }

    /**
     * Seul le jour civil affiché compte : convertir le fuseau décalerait la
     * date d'un séjour saisi près de minuit.
     */
    private static function normalise(DateTimeImmutable $day): DateTimeImmutable
    {
        return self::parseDay($day->format('Y-m-d'));
    }
}
