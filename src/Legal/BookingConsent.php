<?php

declare(strict_types=1);

namespace SecondStay\Legal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Ce qu'un séjour a accepté : le texte, sa version, et la langue dans
 * laquelle il a été lu.
 *
 * La langue compte autant que la version : accepter des conditions en
 * néerlandais et se voir opposer la version française serait sans valeur.
 */
final class BookingConsent
{
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly LegalDocumentType $type,
        public readonly string $version,
        public readonly string $locale,
        public readonly ?int $documentId,
        public readonly string $sha256,
        public readonly string $acceptedAt,
        public readonly string $ipHash,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['booking_id'],
            LegalDocumentType::fromString((string) $row['type']),
            (string) $row['version'],
            (string) $row['locale'],
            $row['document_id'] === null ? null : (int) $row['document_id'],
            (string) $row['sha256'],
            (string) $row['accepted_at'],
            (string) $row['ip_hash'],
        );
    }

    public function acceptedDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->acceptedAt, new DateTimeZone('UTC'));
    }
}
