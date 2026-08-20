<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Pricing\DateRange;

/**
 * Plage de séjour : arrivée incluse, départ exclu.
 *
 * C'est la convention qui décide si deux séjours se chevauchent : une erreur
 * d'un jour ici produit soit une double réservation, soit une nuit vendue
 * deux fois.
 */
final class DateRangeTest extends TestCase
{
    public function testASevenNightStayCountsSevenNights(): void
    {
        $range = DateRange::fromStrings('2026-07-12', '2026-07-19');

        self::assertSame(7, $range->nights());
        self::assertCount(7, $range->nightKeys());
        self::assertSame('2026-07-12', $range->nightKeys()[0]);
        // La nuit du départ n'existe pas : la dernière est celle du 18.
        self::assertSame('2026-07-18', $range->lastNightKey());
        self::assertSame('2026-07-19', $range->departureKey());
    }

    public function testASingleNightIsValid(): void
    {
        $range = DateRange::fromStrings('2026-07-12', '2026-07-13');

        self::assertTrue($range->isValid());
        self::assertSame(1, $range->nights());
        self::assertSame(['2026-07-12'], $range->nightKeys());
    }

    /**
     * @return list<array{string, string, bool}>
     */
    public static function validity(): array
    {
        return [
            ['2026-07-12', '2026-07-13', true],
            ['2026-07-12', '2026-07-12', false],
            ['2026-07-13', '2026-07-12', false],
            ['2026-01-01', '2026-12-31', true],
            // Plus d'un an : hors de tout usage réel.
            ['2026-01-01', '2027-06-01', false],
        ];
    }

    #[DataProvider('validity')]
    public function testValidityBounds(string $arrival, string $departure, bool $expected): void
    {
        self::assertSame($expected, DateRange::fromStrings($arrival, $departure)->isValid());
    }

    /**
     * @return list<array{string}>
     */
    public static function malformedDates(): array
    {
        return [[''], ['12/07/2026'], ['2026-13-01'], ['2026-02-30'], ['hier'], ['2026-07-12T10:00'], ['20260712']];
    }

    #[DataProvider('malformedDates')]
    public function testMalformedDatesAreRefused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('booking.error.invalid_date');

        DateRange::fromStrings($value, '2026-07-19');
    }

    /**
     * @return list<array{string, string, bool}>
     */
    public static function overlaps(): array
    {
        return [
            // Un départ le jour d'une arrivée ne se chevauche pas : c'est le
            // cas exact d'un enchaînement de deux séjours.
            ['2026-07-19', '2026-07-26', false],
            ['2026-07-05', '2026-07-12', false],
            // Une seule nuit commune suffit.
            ['2026-07-18', '2026-07-20', true],
            ['2026-07-11', '2026-07-13', true],
            // Séjour englobant ou englobé.
            ['2026-07-01', '2026-08-01', true],
            ['2026-07-14', '2026-07-15', true],
            ['2026-07-12', '2026-07-19', true],
        ];
    }

    #[DataProvider('overlaps')]
    public function testOverlapFollowsTheNightConvention(string $arrival, string $departure, bool $expected): void
    {
        $stay = DateRange::fromStrings('2026-07-12', '2026-07-19');
        $other = DateRange::fromStrings($arrival, $departure);

        self::assertSame($expected, $stay->overlaps($other));
        // Le chevauchement est symétrique.
        self::assertSame($expected, $other->overlaps($stay));
    }

    public function testFromNightsIncludesTheLastNight(): void
    {
        $range = DateRange::fromNights('2026-07-12', '2026-07-18');

        self::assertSame(7, $range->nights());
        self::assertSame('2026-07-19', $range->departureKey());
        self::assertTrue($range->equals(DateRange::fromStrings('2026-07-12', '2026-07-19')));
    }

    public function testContainsCoversNightsOnly(): void
    {
        $range = DateRange::fromStrings('2026-07-12', '2026-07-19');

        self::assertTrue($range->contains(new DateTimeImmutable('2026-07-12')));
        self::assertTrue($range->contains(new DateTimeImmutable('2026-07-18')));
        // Le jour du départ n'appartient pas au séjour.
        self::assertFalse($range->contains(new DateTimeImmutable('2026-07-19')));
        self::assertFalse($range->contains(new DateTimeImmutable('2026-07-11')));
    }

    public function testTheCalendarDayIsKeptWhateverTheTimezone(): void
    {
        // 23 h 30 à Paris est déjà le lendemain en UTC : la date affichée doit
        // pourtant rester celle que le visiteur a choisie.
        $late = new DateTimeImmutable('2026-07-12 23:30', new DateTimeZone('Europe/Paris'));
        $range = DateRange::create($late, $late->modify('+7 days'));

        self::assertSame('2026-07-12', $range->arrivalKey());
        self::assertSame('2026-07-19', $range->departureKey());
        self::assertSame(7, $range->nights());
    }

    public function testASummerTimeChangeDoesNotShiftTheNightCount(): void
    {
        // Le passage à l'heure d'été a lieu dans la nuit du 28 au 29 mars 2026
        // en France : la nuit « courte » compte comme les autres.
        $range = DateRange::fromStrings('2026-03-27', '2026-03-31');

        self::assertSame(4, $range->nights());
        self::assertSame(
            ['2026-03-27', '2026-03-28', '2026-03-29', '2026-03-30'],
            $range->nightKeys()
        );
    }

    public function testAStayAcrossAYearBoundaryIsContinuous(): void
    {
        $range = DateRange::fromStrings('2026-12-28', '2027-01-04');

        self::assertSame(7, $range->nights());
        self::assertContains('2026-12-31', $range->nightKeys());
        self::assertContains('2027-01-01', $range->nightKeys());
    }

    public function testALeapDayIsANightLikeAnyOther(): void
    {
        $range = DateRange::fromStrings('2028-02-27', '2028-03-02');

        self::assertSame(4, $range->nights());
        self::assertContains('2028-02-29', $range->nightKeys());
    }

    public function testStringRepresentationIsStable(): void
    {
        self::assertSame(
            '2026-07-12→2026-07-19',
            (string) DateRange::fromStrings('2026-07-12', '2026-07-19')
        );
    }
}
