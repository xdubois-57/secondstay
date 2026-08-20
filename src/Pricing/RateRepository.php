<?php

declare(strict_types=1);

namespace SecondStay\Pricing;

use SecondStay\Database\Database;

/**
 * Tarifs par nuit.
 *
 * L'absence de ligne pour une date signifie « tarif par défaut » : la table ne
 * contient que les exceptions, ce qui garde un calendrier de plusieurs années
 * peu coûteux à stocker comme à lire.
 */
final class RateRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Tarifs d'une plage, indexés par date ISO.
     *
     * @return array<string, array{price_cents: int, min_nights: ?int, note: string}>
     */
    public function forRange(string $from, string $to): array
    {
        $rows = $this->database->fetchAll(
            'SELECT `day`, `price_cents`, `min_nights`, `note` FROM `rate_override` '
            . 'WHERE `day` >= :from AND `day` <= :to ORDER BY `day`',
            ['from' => $from, 'to' => $to]
        );

        $rates = [];
        foreach ($rows as $row) {
            $rates[(string) $row['day']] = [
                'price_cents' => (int) $row['price_cents'],
                'min_nights' => $row['min_nights'] === null ? null : (int) $row['min_nights'],
                'note' => (string) $row['note'],
            ];
        }

        return $rates;
    }

    /**
     * @return array{price_cents: int, min_nights: ?int, note: string}|null
     */
    public function forDay(string $day): ?array
    {
        return $this->forRange($day, $day)[$day] ?? null;
    }

    /**
     * Applique un tarif à toutes les nuits d'une plage.
     *
     * @return int nombre de nuits modifiées
     */
    public function applyToRange(DateRange $range, int $priceCents, ?int $minNights = null, string $note = ''): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $changed = 0;

        $this->database->transaction(function () use ($range, $priceCents, $minNights, $note, $now, &$changed): void {
            foreach ($range->nightKeys() as $day) {
                $this->database->execute(
                    'INSERT INTO `rate_override` (`day`, `price_cents`, `min_nights`, `note`, `updated_at`) '
                    . 'VALUES (:day, :price, :min_nights, :note, :updated_at) '
                    . 'ON DUPLICATE KEY UPDATE `price_cents` = VALUES(`price_cents`), '
                    . '`min_nights` = VALUES(`min_nights`), `note` = VALUES(`note`), '
                    . '`updated_at` = VALUES(`updated_at`)',
                    [
                        'day' => $day,
                        'price' => $priceCents,
                        'min_nights' => $minNights,
                        'note' => mb_substr($note, 0, 190),
                        'updated_at' => $now,
                    ]
                );
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * Retire les exceptions d'une plage : les nuits reviennent au tarif par
     * défaut.
     */
    public function clearRange(DateRange $range): int
    {
        return $this->database->execute(
            'DELETE FROM `rate_override` WHERE `day` >= :from AND `day` <= :to',
            ['from' => $range->arrivalKey(), 'to' => $range->lastNightKey()]
        )->rowCount();
    }

    public function purgeBefore(string $day): int
    {
        return $this->database->execute(
            'DELETE FROM `rate_override` WHERE `day` < :day',
            ['day' => $day]
        )->rowCount();
    }
}
