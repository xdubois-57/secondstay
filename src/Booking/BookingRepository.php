<?php

declare(strict_types=1);

namespace SecondStay\Booking;

use PDOException;
use SecondStay\Database\Database;
use SecondStay\Pricing\DateRange;

/**
 * Persistance des séjours et **garantie transactionnelle** contre la double
 * réservation (SPECIFICATIONS.md §27).
 *
 * La garantie ne repose pas sur une vérification suivie d'une écriture — deux
 * requêtes concurrentes passeraient toutes deux la vérification — mais sur la
 * clé primaire de `booking_night` : une nuit occupée y existe une fois et une
 * seule. La seconde insertion échoue, quel que soit l'ordre d'exécution.
 */
final class BookingRepository
{
    /** Code SQLSTATE d'une violation de contrainte d'unicité. */
    private const INTEGRITY_VIOLATION = '23000';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Réserve les nuits d'un séjour.
     *
     * @param array<string, mixed> $data
     *
     * @return array{ok: true, id: int, reference: string}|array{ok: false, error: string}
     */
    public function insertWithNights(array $data, DateRange $range): array
    {
        try {
            /** @var array{ok: true, id: int, reference: string} $result */
            $result = $this->database->transaction(function () use ($data, $range): array {
                $id = $this->database->insert('booking', $data);

                foreach ($range->nightKeys() as $day) {
                    // Une nuit déjà prise fait échouer la transaction entière.
                    $this->database->insert('booking_night', ['day' => $day, 'booking_id' => $id]);
                }

                return ['ok' => true, 'id' => $id, 'reference' => (string) $data['reference']];
            });

            return $result;
        } catch (PDOException $exception) {
            if ($exception->getCode() === self::INTEGRITY_VIOLATION) {
                return ['ok' => false, 'error' => 'booking.error.unavailable'];
            }

            throw $exception;
        }
    }

    /**
     * Libère les nuits d'un séjour : annulation, refus ou expiration.
     */
    public function releaseNights(int $bookingId): int
    {
        return $this->database->delete('booking_night', ['booking_id' => $bookingId]);
    }

    /**
     * Nuits déjà prises dans une plage, quelle qu'en soit la réservation.
     *
     * @return list<string>
     */
    public function occupiedNights(string $from, string $to): array
    {
        $rows = $this->database->fetchAll(
            'SELECT `day` FROM `booking_night` WHERE `day` >= :from AND `day` <= :to ORDER BY `day`',
            ['from' => $from, 'to' => $to]
        );

        return array_map(static fn (array $row): string => (string) $row['day'], $rows);
    }

    /**
     * @return array<string, int> nuit → identifiant de réservation
     */
    public function nightOwners(string $from, string $to): array
    {
        $rows = $this->database->fetchAll(
            'SELECT `day`, `booking_id` FROM `booking_night` WHERE `day` >= :from AND `day` <= :to',
            ['from' => $from, 'to' => $to]
        );

        $owners = [];
        foreach ($rows as $row) {
            $owners[(string) $row['day']] = (int) $row['booking_id'];
        }

        return $owners;
    }

    public function find(int $id): ?Booking
    {
        $row = $this->database->fetchOne('SELECT * FROM `booking` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : Booking::fromRow($row);
    }

    /**
     * Séjours dont l'arrivée tombe dans une fenêtre, du plus proche au plus
     * lointain.
     *
     * Sert à la préparation des séjours : seuls ceux qui occupent réellement
     * des nuits sont concernés.
     *
     * @return list<Booking>
     */
    public function arrivingBetween(string $from, string $to): array
    {
        return array_map(
            static fn (array $row): Booking => Booking::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `booking` WHERE `arrival` BETWEEN :from AND :to '
                . 'AND `status` NOT IN (:cancelled, :refused, :hold) ORDER BY `arrival` LIMIT 200',
                [
                    'from' => $from,
                    'to' => $to,
                    'cancelled' => BookingStatus::Cancelled->value,
                    'refused' => BookingStatus::Refused->value,
                    'hold' => BookingStatus::Hold->value,
                ]
            )
        );
    }

    /**
     * Séjours affectés à un responsable, à venir.
     *
     * @return list<Booking>
     */
    public function forManager(int $managerId, ?string $from = null): array
    {
        return array_map(
            static fn (array $row): Booking => Booking::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `booking` WHERE `manager_id` = :manager AND `departure` >= :from '
                . 'AND `status` NOT IN (:cancelled, :refused) ORDER BY `arrival` LIMIT 200',
                [
                    'manager' => $managerId,
                    'from' => $from ?? gmdate('Y-m-d'),
                    'cancelled' => BookingStatus::Cancelled->value,
                    'refused' => BookingStatus::Refused->value,
                ]
            )
        );
    }

    /**
     * Séjours en cours associés à une adresse.
     *
     * Sert de dernier recours au rattachement du courrier entrant : si
     * l'adresse en désigne plusieurs, aucune conclusion n'est possible.
     *
     * @return list<Booking>
     */
    public function activeForEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        return array_map(
            static fn (array $row): Booking => Booking::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `booking` WHERE LOWER(`guest_email`) = :email '
                . 'AND `status` NOT IN (:cancelled, :refused) ORDER BY `arrival` DESC LIMIT 10',
                [
                    'email' => $email,
                    'cancelled' => BookingStatus::Cancelled->value,
                    'refused' => BookingStatus::Refused->value,
                ]
            )
        );
    }

    public function findByReference(string $reference): ?Booking
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `booking` WHERE `reference` = :reference',
            ['reference' => BookingReference::normalise($reference)]
        );

        return $row === null ? null : Booking::fromRow($row);
    }

    public function referenceExists(string $reference): bool
    {
        return $this->database->fetchOne(
            'SELECT `id` FROM `booking` WHERE `reference` = :reference',
            ['reference' => $reference]
        ) !== null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('booking', $data + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * @return list<Booking>
     */
    public function forUser(int $userId): array
    {
        return array_map(
            static fn (array $row): Booking => Booking::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `booking` WHERE `user_id` = :user ORDER BY `arrival` DESC',
                ['user' => $userId]
            )
        );
    }

    /**
     * @param list<BookingStatus> $statuses
     *
     * @return list<Booking>
     */
    public function listing(array $statuses = [], int $limit = 100): array
    {
        $sql = 'SELECT * FROM `booking`';
        $parameters = [];

        if ($statuses !== []) {
            $placeholders = [];
            foreach ($statuses as $index => $status) {
                $placeholders[] = ':status' . $index;
                $parameters['status' . $index] = $status->value;
            }
            $sql .= ' WHERE `status` IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY `arrival` DESC LIMIT ' . max(1, min(500, $limit));

        return array_map(
            static fn (array $row): Booking => Booking::fromRow($row),
            $this->database->fetchAll($sql, $parameters)
        );
    }

    /**
     * Verrous temporaires arrivés à expiration.
     *
     * @return list<Booking>
     */
    public function expiredHolds(?string $now = null): array
    {
        return array_map(
            static fn (array $row): Booking => Booking::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `booking` WHERE `status` = :status AND `expires_at` IS NOT NULL '
                . 'AND `expires_at` < :now',
                ['status' => BookingStatus::Hold->value, 'now' => $now ?? gmdate('Y-m-d H:i:s')]
            )
        );
    }

    /**
     * @return list<Booking> séjours dont l'arrivée est passée mais toujours ouverts
     */
    public function startingOn(string $day): array
    {
        return array_map(
            static fn (array $row): Booking => Booking::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `booking` WHERE `arrival` = :day AND `status` = :status',
                ['day' => $day, 'status' => BookingStatus::Confirmed->value]
            )
        );
    }

    public function countByStatus(BookingStatus $status): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `booking` WHERE `status` = :status',
            ['status' => $status->value]
        );
    }
}
