<?php

declare(strict_types=1);

namespace SecondStay\Document;

use SecondStay\Database\Database;

final class DocumentRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $data['created_at'] ??= gmdate('Y-m-d H:i:s');

        return $this->database->insert('document', $data);
    }

    public function find(int $id): ?Document
    {
        $row = $this->database->fetchOne('SELECT * FROM `document` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : Document::fromRow($row);
    }

    /**
     * @return list<Document>
     */
    public function forBooking(int $bookingId): array
    {
        return array_map(
            static fn (array $row): Document => Document::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `document` WHERE `booking_id` = :booking ORDER BY `created_at` DESC, `id` DESC',
                ['booking' => $bookingId]
            )
        );
    }

    /**
     * Dernier document d'une nature donnée pour un séjour.
     */
    public function latestKind(int $bookingId, DocumentKind $kind): ?Document
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `document` WHERE `booking_id` = :booking AND `kind` = :kind '
            . 'ORDER BY `id` DESC LIMIT 1',
            ['booking' => $bookingId, 'kind' => $kind->value]
        );

        return $row === null ? null : Document::fromRow($row);
    }

    /**
     * Un document identique est déjà rattaché au séjour ?
     *
     * L'empreinte évite qu'une pièce jointe reçue deux fois — réponse citée,
     * renvoi manuel — apparaisse deux fois dans les documents du séjour.
     */
    /**
     * Un enregistrement référence-t-il encore ce contenu ?
     *
     * Le fichier est nommé par son empreinte : il peut être partagé par
     * plusieurs séjours. Interroger un seul séjour ne suffit donc pas à
     * décider qu'il est orphelin.
     */
    public function existsWithHash(string $sha256): bool
    {
        if ($sha256 === '') {
            return false;
        }

        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `document` WHERE `sha256` = :hash',
            ['hash' => $sha256]
        ) > 0;
    }

    public function findByHash(?int $bookingId, string $sha256): ?Document
    {
        if ($sha256 === '') {
            return null;
        }

        $row = $bookingId === null
            ? $this->database->fetchOne(
                'SELECT * FROM `document` WHERE `booking_id` IS NULL AND `sha256` = :hash LIMIT 1',
                ['hash' => $sha256]
            )
            : $this->database->fetchOne(
                'SELECT * FROM `document` WHERE `booking_id` = :booking AND `sha256` = :hash LIMIT 1',
                ['booking' => $bookingId, 'hash' => $sha256]
            );

        return $row === null ? null : Document::fromRow($row);
    }

    /**
     * @return list<Document>
     */
    public function forMail(int $mailId): array
    {
        return array_map(
            static fn (array $row): Document => Document::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `document` WHERE `mail_id` = :mail ORDER BY `id`',
                ['mail' => $mailId]
            )
        );
    }

    /**
     * Documents les plus récents, tous séjours confondus.
     *
     * @return list<array{document: Document, reference: string}>
     */
    public function recent(int $limit = 100): array
    {
        $rows = $this->database->fetchAll(
            'SELECT d.*, b.`reference` AS `booking_reference` FROM `document` d '
            . 'LEFT JOIN `booking` b ON b.`id` = d.`booking_id` '
            . 'ORDER BY d.`id` DESC LIMIT ' . max(1, min(500, $limit))
        );

        return array_map(
            static fn (array $row): array => [
                'document' => Document::fromRow($row),
                'reference' => (string) ($row['booking_reference'] ?? ''),
            ],
            $rows
        );
    }

    public function reclassify(int $id, DocumentKind $kind): void
    {
        $this->database->update('document', ['kind' => $kind->value], ['id' => $id]);
    }

    public function attachToBooking(int $id, int $bookingId): void
    {
        $this->database->update('document', ['booking_id' => $bookingId], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM `document` WHERE `id` = :id', ['id' => $id]);
    }
}
