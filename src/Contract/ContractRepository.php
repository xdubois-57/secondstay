<?php

declare(strict_types=1);

namespace SecondStay\Contract;

use SecondStay\Database\Database;

final class ContractRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(array $data): int
    {
        $data['accepted_at'] ??= gmdate('Y-m-d H:i:s');

        return $this->database->insert('contract_acceptance', $data);
    }

    public function forBooking(int $bookingId): ?ContractAcceptance
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `contract_acceptance` WHERE `booking_id` = :booking',
            ['booking' => $bookingId]
        );

        return $row === null ? null : ContractAcceptance::fromRow($row);
    }

    /**
     * @return list<ContractAcceptance>
     */
    public function all(int $limit = 200): array
    {
        return array_map(
            static fn (array $row): ContractAcceptance => ContractAcceptance::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `contract_acceptance` ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit))
            )
        );
    }
}
