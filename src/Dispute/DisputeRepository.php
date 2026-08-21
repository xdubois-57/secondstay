<?php

declare(strict_types=1);

namespace SecondStay\Dispute;

use PDOException;
use SecondStay\Database\Database;

final class DisputeRepository
{
    private const INTEGRITY_VIOLATION = '23000';

    private const SELECT = 'SELECT d.*, b.`reference` AS `booking_reference` FROM `dispute` d '
        . 'INNER JOIN `booking` b ON b.`id` = d.`booking_id` ';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Ouvre un litige, une seule fois par séjour et par nature.
     *
     * @param array<string, mixed> $data
     */
    public function open(int $bookingId, string $kind, array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        try {
            return $this->database->insert('dispute', $data + [
                'booking_id' => $bookingId,
                'kind' => $kind,
                'status' => DisputeStatus::Open->value,
                'opened_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== self::INTEGRITY_VIOLATION) {
                throw $exception;
            }

            // Un litige de cette nature existe déjà : la seconde ouverture
            // n'en crée pas un autre et n'écrase pas le premier. Zéro dit à
            // l'appelant qu'il n'a rien ouvert, à lui de le signaler.
            return 0;
        }
    }

    public function find(int $id): ?Dispute
    {
        $row = $this->database->fetchOne(self::SELECT . 'WHERE d.`id` = :id', ['id' => $id]);

        return $row === null ? null : Dispute::fromRow($row, $this->eventsFor($id));
    }

    public function findFor(int $bookingId, string $kind): ?Dispute
    {
        $row = $this->database->fetchOne(
            self::SELECT . 'WHERE d.`booking_id` = :booking AND d.`kind` = :kind',
            ['booking' => $bookingId, 'kind' => $kind]
        );

        return $row === null ? null : Dispute::fromRow($row, $this->eventsFor((int) $row['id']));
    }

    /**
     * @return list<Dispute>
     */
    public function listing(?DisputeStatus $status = null, int $limit = 200): array
    {
        $where = $status === null ? '' : 'WHERE d.`status` = :status ';
        $parameters = $status === null ? [] : ['status' => $status->value];

        return array_map(
            static fn (array $row): Dispute => Dispute::fromRow($row),
            $this->database->fetchAll(
                self::SELECT . $where . 'ORDER BY d.`status`, d.`opened_at` DESC, d.`id` DESC LIMIT '
                . max(1, min(500, $limit)),
                $parameters
            )
        );
    }

    /**
     * @return list<Dispute>
     */
    public function forBooking(int $bookingId): array
    {
        return array_map(
            static fn (array $row): Dispute => Dispute::fromRow($row),
            $this->database->fetchAll(
                self::SELECT . 'WHERE d.`booking_id` = :booking ORDER BY d.`id`',
                ['booking' => $bookingId]
            )
        );
    }

    public function countOpen(): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `dispute` WHERE `status` <> :resolved',
            ['resolved' => DisputeStatus::Resolved->value]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('dispute', $data + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function addEvent(int $disputeId, string $type, string $note, ?int $actorId, string $actorLabel): int
    {
        return $this->database->insert('dispute_event', [
            'dispute_id' => $disputeId,
            'type' => mb_substr($type, 0, 32),
            'note' => mb_substr($note, 0, 255),
            'actor_id' => $actorId,
            'actor_label' => mb_substr($actorLabel, 0, 190),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<DisputeEvent>
     */
    public function eventsFor(int $disputeId): array
    {
        return array_map(
            static fn (array $row): DisputeEvent => DisputeEvent::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `dispute_event` WHERE `dispute_id` = :dispute ORDER BY `id`',
                ['dispute' => $disputeId]
            )
        );
    }
}
