<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Reporting\Report;
use SecondStay\Reporting\ReportPeriod;

/**
 * Périodes et indicateurs de reporting (SPECIFICATIONS.md §66).
 *
 * Le rapport compte ; il ne conseille pas. Ces tests fixent ce que « compter »
 * veut dire — notamment qu'une caution n'est pas un revenu et qu'un taux
 * d'occupation sans nuit ouverte vaut zéro, pas une division par zéro.
 */
final class ReportingTest extends TestCase
{
    public function testAMonthSpansItsFirstAndLastDay(): void
    {
        $period = ReportPeriod::month(2026, 2);

        self::assertSame('2026-02-01', $period->from);
        self::assertSame('2026-02-28', $period->to);
        self::assertSame('2026-02', $period->label);
        self::assertFalse($period->isYear);
    }

    public function testAFebruaryInALeapYearHasTwentyNineDays(): void
    {
        self::assertSame('2028-02-29', ReportPeriod::month(2028, 2)->to);
        self::assertSame(29, ReportPeriod::month(2028, 2)->nights());
    }

    public function testAYearSpansTwelveMonths(): void
    {
        $period = ReportPeriod::year(2026);

        self::assertSame('2026-01-01', $period->from);
        self::assertSame('2026-12-31', $period->to);
        self::assertSame('2026', $period->label);
        self::assertTrue($period->isYear);
        self::assertSame(365, $period->nights());
    }

    public function testAnOutOfRangeMonthIsClamped(): void
    {
        self::assertSame('2026-12', ReportPeriod::month(2026, 99)->label);
        self::assertSame('2026-01', ReportPeriod::month(2026, 0)->label);
    }

    public function testAnAbsurdYearIsClamped(): void
    {
        self::assertSame('2000', ReportPeriod::year(1200)->label);
        self::assertSame('2100', ReportPeriod::year(9999)->label);
    }

    public function testContainsUsesInclusiveBounds(): void
    {
        $period = ReportPeriod::month(2026, 6);

        self::assertTrue($period->contains('2026-06-01'));
        self::assertTrue($period->contains('2026-06-30'));
        self::assertFalse($period->contains('2026-05-31'));
        self::assertFalse($period->contains('2026-07-01'));
    }

    /**
     * @param list<array{
     *     reference: string, arrival: string, departure: string, nights: int,
     *     nights_in_period: int, status: string, received_cents: int,
     *     expected_cents: int, tax_cents: int, deposit_held_cents: int
     * }> $stays
     */
    private function report(
        int $received = 0,
        int $expected = 0,
        int $nightsSold = 0,
        int $nightsAvailable = 30,
        int $stayCount = 0,
        array $stays = [],
    ): Report {
        return new Report(
            ReportPeriod::month(2026, 6),
            $received,
            $expected,
            0,
            0,
            0,
            $nightsSold,
            $nightsAvailable,
            $stayCount,
            'EUR',
            $stays,
        );
    }

    public function testOccupancyIsARoundedPercentage(): void
    {
        self::assertSame(50.0, $this->report(nightsSold: 15, nightsAvailable: 30)->occupancyPercent());
        self::assertSame(33.3, $this->report(nightsSold: 10, nightsAvailable: 30)->occupancyPercent());
    }

    public function testOccupancyIsZeroWhenNoNightIsOpen(): void
    {
        self::assertSame(0.0, $this->report(nightsSold: 4, nightsAvailable: 0)->occupancyPercent());
    }

    public function testTheAverageNightIsReceivedOverNightsSold(): void
    {
        self::assertSame(10_000, $this->report(received: 100_000, nightsSold: 10)->averageNightCents());
    }

    public function testTheAverageNightIsZeroWithoutANightSold(): void
    {
        self::assertSame(0, $this->report(received: 100_000, nightsSold: 0)->averageNightCents());
    }

    public function testOutstandingIsWhatIsExpectedMinusWhatIsReceived(): void
    {
        self::assertSame(40_000, $this->report(received: 60_000, expected: 100_000)->outstandingCents());
    }

    public function testOutstandingNeverGoesNegative(): void
    {
        // Un encaissement supérieur à l'attendu n'est pas une dette du
        // propriétaire envers lui-même.
        self::assertSame(0, $this->report(received: 120_000, expected: 100_000)->outstandingCents());
    }

    public function testAPeriodWithoutStayIsEmpty(): void
    {
        self::assertTrue($this->report()->isEmpty());
        self::assertFalse($this->report(stayCount: 1)->isEmpty());
    }
}
