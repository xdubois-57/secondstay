<?php

declare(strict_types=1);

namespace SecondStay\Imap;

use PDOException;
use SecondStay\Database\Database;

/**
 * Persistance du courrier entrant, dans la même table que le courrier sortant
 * afin que la timeline de communication soit une seule liste ordonnée
 * (SPECIFICATIONS.md §37).
 */
final class InboundMailRepository
{
    /** Violation de contrainte d'intégrité : le message est déjà importé. */
    private const INTEGRITY_VIOLATION = '23000';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Enregistre un message entrant.
     *
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, id: int, duplicate: bool}
     */
    public function store(array $data): array
    {
        $data['created_at'] ??= gmdate('Y-m-d H:i:s');
        $data['direction'] = 'inbound';
        $data['status'] = 'received';

        try {
            return ['ok' => true, 'id' => $this->database->insert('mail_message', $data), 'duplicate' => false];
        } catch (PDOException $exception) {
            if ($exception->getCode() !== self::INTEGRITY_VIOLATION) {
                throw $exception;
            }

            // Une synchronisation qui repart en arrière ne réimporte rien :
            // l'unicité (boîte, UID) le garantit en base.
            $existing = $this->findByUid((string) $data['mailbox'], (int) $data['uid']);

            return ['ok' => true, 'id' => $existing === null ? 0 : (int) $existing['id'], 'duplicate' => true];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(string $mailbox, int $uid): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM `mail_message` WHERE `mailbox` = :mailbox AND `uid` = :uid',
            ['mailbox' => $mailbox, 'uid' => $uid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM `mail_message` WHERE `id` = :id', ['id' => $id]);
    }

    /**
     * Séjour d'un message sortant portant cet identifiant.
     *
     * C'est la voie « en-têtes de fil » : la réponse cite le `Message-ID` du
     * message que SecondStay a envoyé.
     */
    public function bookingOfMessageId(string $messageId): ?int
    {
        if ($messageId === '') {
            return null;
        }

        $row = $this->database->fetchOne(
            'SELECT `booking_id` FROM `mail_message` WHERE `message_id` = :id AND `booking_id` IS NOT NULL LIMIT 1',
            ['id' => $messageId]
        );

        return $row === null || $row['booking_id'] === null ? null : (int) $row['booking_id'];
    }

    /**
     * Timeline de communication d'un séjour, du plus ancien au plus récent.
     *
     * @return list<array<string, mixed>>
     */
    public function forBooking(int $bookingId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `mail_message` WHERE `booking_id` = :booking ORDER BY `created_at`, `id`',
            ['booking' => $bookingId]
        );
    }

    /**
     * Messages entrants qu'aucune règle n'a su rattacher.
     *
     * @return list<array<string, mixed>>
     */
    public function unlinked(int $limit = 100): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `mail_message` WHERE `direction` = :direction AND `booking_id` IS NULL '
            . 'ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit)),
            ['direction' => 'inbound']
        );
    }

    /**
     * Nombre de courriers entrants qu'aucune règle n'a su rattacher.
     *
     * Distinct de `unlinked()`, qui rend une page : le tableau « À faire »
     * affiche une quantité, et une quantité plafonnée à la taille d'une page
     * ferait croire au propriétaire qu'il a fini bien avant la fin.
     */
    public function countUnlinked(): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `mail_message` WHERE `direction` = :direction AND `booking_id` IS NULL',
            ['direction' => 'inbound']
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentInbound(int $limit = 100): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `mail_message` WHERE `direction` = :direction ORDER BY `id` DESC LIMIT '
            . max(1, min(500, $limit)),
            ['direction' => 'inbound']
        );
    }

    public function link(int $id, int $bookingId, LinkMethod $method): void
    {
        $this->database->update(
            'mail_message',
            ['booking_id' => $bookingId, 'linked_by' => $method->value],
            ['id' => $id]
        );
    }

    /**
     * Plus grand UID déjà importé pour une boîte.
     */
    public function lastUid(string $mailbox): int
    {
        $row = $this->database->fetchOne(
            'SELECT MAX(`uid`) AS `last` FROM `mail_message` WHERE `mailbox` = :mailbox',
            ['mailbox' => $mailbox]
        );

        return $row === null || $row['last'] === null ? 0 : (int) $row['last'];
    }
}
