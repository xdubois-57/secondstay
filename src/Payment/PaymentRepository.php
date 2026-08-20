<?php

declare(strict_types=1);

namespace SecondStay\Payment;

use SecondStay\Database\Database;

final class PaymentRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->database->insert('payment', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('payment', $data + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function find(int $id): ?Payment
    {
        $row = $this->database->fetchOne('SELECT * FROM `payment` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : Payment::fromRow($row);
    }

    public function findByReference(string $provider, string $reference): ?Payment
    {
        if ($reference === '') {
            return null;
        }

        $row = $this->database->fetchOne(
            'SELECT * FROM `payment` WHERE `provider` = :provider AND `provider_reference` = :reference',
            ['provider' => $provider, 'reference' => $reference]
        );

        return $row === null ? null : Payment::fromRow($row);
    }

    /**
     * @return list<Payment>
     */
    public function forBooking(int $bookingId): array
    {
        return array_map(
            static fn (array $row): Payment => Payment::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `payment` WHERE `booking_id` = :booking ORDER BY `id`',
                ['booking' => $bookingId]
            )
        );
    }

    public function findKind(int $bookingId, PaymentKind $kind): ?Payment
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `payment` WHERE `booking_id` = :booking AND `kind` = :kind ORDER BY `id` LIMIT 1',
            ['booking' => $bookingId, 'kind' => $kind->value]
        );

        return $row === null ? null : Payment::fromRow($row);
    }

    /**
     * Somme réellement acquise pour un séjour : les cautions et les
     * remboursements n'y entrent pas.
     */
    public function revenueCents(int $bookingId): int
    {
        $total = 0;
        foreach ($this->forBooking($bookingId) as $payment) {
            if ($payment->kind->isRevenue()) {
                $total += $payment->netCents();
            }
        }

        return $total;
    }

    /**
     * @return list<Payment>
     */
    public function overdue(?string $today = null): array
    {
        return array_map(
            static fn (array $row): Payment => Payment::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `payment` WHERE `due_on` IS NOT NULL AND `due_on` < :today '
                . 'AND `status` NOT IN (:paid, :refunded, :cancelled) ORDER BY `due_on`',
                [
                    'today' => $today ?? gmdate('Y-m-d'),
                    'paid' => PaymentStatus::Paid->value,
                    'refunded' => PaymentStatus::Refunded->value,
                    'cancelled' => PaymentStatus::Cancelled->value,
                ]
            )
        );
    }

    /**
     * Échéances non réglées, du plus urgent au plus lointain.
     *
     * La référence du séjour accompagne chaque ligne : sans elle, la page de
     * suivi n'afficherait que des montants sans savoir à qui les réclamer.
     *
     * @return list<array{payment: Payment, reference: string, arrival: string}>
     */
    public function outstanding(?string $today = null, int $limit = 100): array
    {
        $rows = $this->database->fetchAll(
            'SELECT p.*, b.`reference` AS `booking_reference`, b.`arrival` AS `booking_arrival` '
            . 'FROM `payment` p INNER JOIN `booking` b ON b.`id` = p.`booking_id` '
            . 'WHERE p.`status` NOT IN (:paid, :refunded, :cancelled) '
            . 'ORDER BY p.`due_on` IS NULL, p.`due_on`, p.`id` LIMIT ' . max(1, min(500, $limit)),
            [
                'paid' => PaymentStatus::Paid->value,
                'refunded' => PaymentStatus::Refunded->value,
                'cancelled' => PaymentStatus::Cancelled->value,
            ]
        );

        return array_map(
            static fn (array $row): array => [
                'payment' => Payment::fromRow($row),
                'reference' => (string) $row['booking_reference'],
                'arrival' => (string) $row['booking_arrival'],
            ],
            $rows
        );
    }

    /**
     * Cautions détenues, à restituer ou partiellement retenues.
     *
     * @return list<array{payment: Payment, reference: string, arrival: string}>
     */
    public function heldDeposits(int $limit = 100): array
    {
        $rows = $this->database->fetchAll(
            'SELECT p.*, b.`reference` AS `booking_reference`, b.`arrival` AS `booking_arrival` '
            . 'FROM `payment` p INNER JOIN `booking` b ON b.`id` = p.`booking_id` '
            . 'WHERE p.`kind` = :kind AND p.`hold_status` IN (:received, :to_return, :retained) '
            . 'ORDER BY b.`arrival` LIMIT ' . max(1, min(500, $limit)),
            [
                'kind' => PaymentKind::SecurityDeposit->value,
                'received' => HoldStatus::Received->value,
                'to_return' => HoldStatus::ToReturn->value,
                'retained' => HoldStatus::PartiallyRetained->value,
            ]
        );

        return array_map(
            static fn (array $row): array => [
                'payment' => Payment::fromRow($row),
                'reference' => (string) $row['booking_reference'],
                'arrival' => (string) $row['booking_arrival'],
            ],
            $rows
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function recordEvent(int $paymentId, string $type, array $data = []): int
    {
        return $this->database->insert('payment_event', [
            'payment_id' => $paymentId,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'type' => mb_substr($type, 0, 48),
            'data' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @return list<array{type: string, created_at: string, data: array<string, mixed>}>
     */
    public function history(int $paymentId): array
    {
        return array_map(
            static function (array $row): array {
                /** @var array<string, mixed>|null $data */
                $data = $row['data'] === null ? null : json_decode((string) $row['data'], true);

                return [
                    'type' => (string) $row['type'],
                    'created_at' => (string) $row['created_at'],
                    'data' => is_array($data) ? $data : [],
                ];
            },
            $this->database->fetchAll(
                'SELECT * FROM `payment_event` WHERE `payment_id` = :payment ORDER BY `id`',
                ['payment' => $paymentId]
            )
        );
    }
}
