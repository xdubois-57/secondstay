<?php

declare(strict_types=1);

namespace SecondStay\Reporting;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Une période de reporting : un mois, ou une année (SPECIFICATIONS.md §66).
 */
final class ReportPeriod
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,
        public readonly bool $isYear,
    ) {
    }

    public static function month(int $year, int $month): self
    {
        $year = max(2000, min(2100, $year));
        $month = max(1, min(12, $month));

        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), new DateTimeZone('UTC'));

        return new self(
            $start->format('Y-m-d'),
            $start->modify('last day of this month')->format('Y-m-d'),
            $start->format('Y-m'),
            false,
        );
    }

    public static function year(int $year): self
    {
        $year = max(2000, min(2100, $year));

        return new self(
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
            (string) $year,
            true,
        );
    }

    /**
     * Nombre de nuits que compte la période.
     */
    public function nights(): int
    {
        $from = new DateTimeImmutable($this->from . ' 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable($this->to . ' 00:00:00', new DateTimeZone('UTC'));

        return (int) $from->diff($to)->days + 1;
    }

    /**
     * La date tombe-t-elle dans la période ?
     */
    public function contains(string $day): bool
    {
        return $day >= $this->from && $day <= $this->to;
    }
}
