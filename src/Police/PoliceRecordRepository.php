<?php

declare(strict_types=1);

namespace SecondStay\Police;

use SecondStay\Database\Database;
use Throwable;
use SecondStay\Security\Encryptor;

/**
 * Registre des fiches de police, chiffré au repos.
 *
 * Le chiffrement n'est pas décoratif : ces lignes portent une identité, une
 * date de naissance et un domicile. Une sauvegarde qui fuit ne doit pas
 * suffire à les lire.
 */
final class PoliceRecordRepository
{
    /** Contexte de chiffrement : une fiche ne se déchiffre pas ailleurs. */
    private const CONTEXT = 'police:record';

    public function __construct(
        private readonly Database $database,
        private readonly Encryptor $encryptor,
    ) {
    }

    /**
     * @param array<string, string> $fields
     */
    public function save(
        int $bookingId,
        array $fields,
        string $locale,
        string $purgeAfter,
        ?int $userId = null,
    ): int {
        $payload = $this->encryptor->encrypt(
            (string) json_encode($fields, JSON_UNESCAPED_UNICODE),
            self::CONTEXT
        );

        $existing = $this->database->fetchOne(
            'SELECT `id` FROM `police_record` WHERE `booking_id` = :booking',
            ['booking' => $bookingId]
        );

        if ($existing === null) {
            return $this->database->insert('police_record', [
                'booking_id' => $bookingId,
                'payload' => $payload,
                'locale' => $locale,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'created_by' => $userId,
                'purge_after' => $purgeAfter,
            ]);
        }

        $this->database->update('police_record', [
            'payload' => $payload,
            'locale' => $locale,
            'purge_after' => $purgeAfter,
        ], ['id' => (int) $existing['id']]);

        return (int) $existing['id'];
    }

    public function forBooking(int $bookingId): ?PoliceRecord
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `police_record` WHERE `booking_id` = :booking',
            ['booking' => $bookingId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<PoliceRecord>
     */
    public function all(int $limit = 200): array
    {
        return array_map(
            fn (array $row): PoliceRecord => $this->hydrate($row),
            $this->database->fetchAll(
                'SELECT * FROM `police_record` ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit))
            )
        );
    }

    public function delete(int $bookingId): void
    {
        $this->database->delete('police_record', ['booking_id' => $bookingId]);
    }

    /**
     * Supprime les fiches dont la durée de conservation est écoulée.
     *
     * La purge est faite **en base**, sans déchiffrer : il n'y a aucune raison
     * de lire une fiche pour l'effacer.
     */
    public function purgeExpired(?string $today = null): int
    {
        return $this->database->execute(
            'DELETE FROM `police_record` WHERE `purge_after` < :today',
            ['today' => $today ?? gmdate('Y-m-d')]
        )->rowCount();
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM `police_record`');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PoliceRecord
    {
        // Une fiche illisible — clé retirée, rotation ratée — ne doit pas
        // faire tomber l'écran qui la liste : elle apparaît vide, ce qui est
        // précisément l'information utile.
        try {
            $plain = $this->encryptor->decrypt((string) $row['payload'], self::CONTEXT);
        } catch (Throwable) {
            $plain = '';
        }

        /** @var array<string, string>|null $fields */
        $fields = $plain === '' ? null : json_decode($plain, true);

        return new PoliceRecord(
            (int) $row['id'],
            (int) $row['booking_id'],
            is_array($fields) ? array_map(static fn (mixed $v): string => (string) $v, $fields) : [],
            (string) $row['locale'],
            (string) $row['created_at'],
            $row['created_by'] === null ? null : (int) $row['created_by'],
            (string) $row['purge_after'],
        );
    }
}
