<?php

declare(strict_types=1);

namespace SecondStay\Content;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Présentation été / hiver (SPECIFICATIONS.md §7).
 *
 * `all` désigne un contenu affiché quelle que soit la saison.
 */
enum Season: string
{
    case All = 'all';
    case Summer = 'summer';
    case Winter = 'winter';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::All;
    }

    /**
     * Saison courante déduite du mois : hiver de novembre à mars.
     */
    public static function current(?DateTimeInterface $now = null): self
    {
        $month = (int) ($now ?? new DateTimeImmutable())->format('n');

        return ($month >= 11 || $month <= 3) ? self::Winter : self::Summer;
    }

    /**
     * Saison effective à partir du réglage `site.season`.
     */
    public static function resolve(string $configured, ?DateTimeInterface $now = null): self
    {
        $season = self::tryFrom(strtolower(trim($configured)));

        if ($season === null || $season === self::All) {
            return self::current($now);
        }

        return $season;
    }

    /**
     * Un contenu est visible si sa saison est `all` ou correspond à la saison
     * effective.
     */
    public function matches(self $effective): bool
    {
        return $this === self::All || $this === $effective;
    }

    public function labelKey(): string
    {
        return 'content.season.' . $this->value;
    }
}
