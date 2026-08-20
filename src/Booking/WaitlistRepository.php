<?php

declare(strict_types=1);

namespace SecondStay\Booking;

use SecondStay\Database\Database;
use SecondStay\Pricing\DateRange;

/**
 * Liste d'attente (SPECIFICATIONS.md §28).
 *
 * Une même adresse ne s'inscrit qu'une fois par période : réessayer ne crée
 * pas de doublon et ne réinitialise pas la date d'inscription.
 */
final class WaitlistRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function add(string $email, DateRange $range, string $locale, ?int $userId = null): int
    {
        $email = mb_strtolower(trim($email));

        $existing = $this->database->fetchOne(
            'SELECT `id` FROM `waitlist_entry` WHERE `email` = :email AND `arrival` = :arrival '
            . 'AND `departure` = :departure',
            ['email' => $email, 'arrival' => $range->arrivalKey(), 'departure' => $range->departureKey()]
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->database->insert('waitlist_entry', [
            'user_id' => $userId,
            'email' => $email,
            'arrival' => $range->arrivalKey(),
            'departure' => $range->departureKey(),
            'locale' => $locale,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Inscriptions dont la période croise les nuits libérées.
     *
     * @param list<string> $freedNights
     *
     * @return list<array<string, mixed>>
     */
    public function matching(array $freedNights): array
    {
        if ($freedNights === []) {
            return [];
        }

        sort($freedNights);
        $first = $freedNights[0];
        $last = $freedNights[count($freedNights) - 1];

        // Deux périodes se croisent dès que chacune commence avant la fin de
        // l'autre ; le départ est exclu, d'où le décalage d'un jour.
        return $this->database->fetchAll(
            'SELECT * FROM `waitlist_entry` WHERE `notified_at` IS NULL '
            . 'AND `arrival` <= :last AND `departure` > :first ORDER BY `id`',
            ['first' => $first, 'last' => $last]
        );
    }

    public function markNotified(int $id): void
    {
        $this->database->update('waitlist_entry', ['notified_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(int $limit = 200): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `waitlist_entry` WHERE `notified_at` IS NULL ORDER BY `arrival` LIMIT '
            . max(1, min(500, $limit))
        );
    }

    public function delete(int $id): bool
    {
        return $this->database->delete('waitlist_entry', ['id' => $id]) > 0;
    }

    public function purgeBefore(string $day): int
    {
        return $this->database->execute(
            'DELETE FROM `waitlist_entry` WHERE `departure` < :day',
            ['day' => $day]
        )->rowCount();
    }
}
