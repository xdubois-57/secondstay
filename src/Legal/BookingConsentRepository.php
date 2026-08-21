<?php

declare(strict_types=1);

namespace SecondStay\Legal;

use PDOException;
use SecondStay\Database\Database;

final class BookingConsentRepository
{
    private const INTEGRITY_VIOLATION = '23000';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Enregistre une acceptation, une seule fois par séjour et par texte.
     *
     * Une acceptation n'est jamais réécrite : ce qui a été accepté l'a été,
     * même si le texte change ensuite.
     */
    public function record(
        int $bookingId,
        LegalDocumentType $type,
        string $version,
        string $locale,
        ?int $documentId,
        string $sha256,
        string $ipHash,
    ): int {
        try {
            return $this->database->insert('booking_consent', [
                'booking_id' => $bookingId,
                'type' => $type->value,
                'version' => mb_substr($version, 0, 32),
                'locale' => $locale,
                'document_id' => $documentId,
                'sha256' => $sha256,
                'accepted_at' => gmdate('Y-m-d H:i:s'),
                'ip_hash' => $ipHash,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== self::INTEGRITY_VIOLATION) {
                throw $exception;
            }

            $existing = $this->find($bookingId, $type);

            return $existing === null ? 0 : $existing->id;
        }
    }

    public function find(int $bookingId, LegalDocumentType $type): ?BookingConsent
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `booking_consent` WHERE `booking_id` = :booking AND `type` = :type',
            ['booking' => $bookingId, 'type' => $type->value]
        );

        return $row === null ? null : BookingConsent::fromRow($row);
    }

    /**
     * @return list<BookingConsent>
     */
    public function forBooking(int $bookingId): array
    {
        return array_map(
            static fn (array $row): BookingConsent => BookingConsent::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `booking_consent` WHERE `booking_id` = :booking ORDER BY `id`',
                ['booking' => $bookingId]
            )
        );
    }
}
