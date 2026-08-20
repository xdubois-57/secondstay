<?php

declare(strict_types=1);

namespace SecondStay\Stay;

use SecondStay\Database\Database;
use SecondStay\Security\Tokens;

final class GuestLinkRepository
{
    /** Trente-deux octets : le lien circule par message, il doit être fort. */
    public const TOKEN_BYTES = 32;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Délivre un lien et renvoie sa valeur en clair, une seule fois.
     *
     * @return array{token: string, id: int}
     */
    public function issue(
        int $bookingId,
        string $expiresAt,
        string $locale,
        string $label = '',
        ?int $createdBy = null,
    ): array {
        $token = Tokens::generateHex(self::TOKEN_BYTES);

        $id = $this->database->insert('guest_link', [
            'booking_id' => $bookingId,
            'token_hash' => Tokens::hash($token),
            'label' => mb_substr($label, 0, 120),
            'locale' => $locale,
            'created_by' => $createdBy,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'id' => $id];
    }

    /**
     * Lien correspondant à une valeur présentée, s'il est encore utilisable.
     *
     * L'expiration est évaluée en base : un appareil dont l'horloge est fausse
     * ne doit pas pouvoir prolonger un lien.
     */
    public function findUsable(string $token, ?string $now = null): ?GuestLink
    {
        if ($token === '') {
            return null;
        }

        $row = $this->database->fetchOne(
            'SELECT * FROM `guest_link` WHERE `token_hash` = :hash AND `revoked_at` IS NULL '
            . 'AND `expires_at` > :now',
            ['hash' => Tokens::hash($token), 'now' => $now ?? gmdate('Y-m-d H:i:s')]
        );

        return $row === null ? null : GuestLink::fromRow($row);
    }

    public function find(int $id): ?GuestLink
    {
        $row = $this->database->fetchOne('SELECT * FROM `guest_link` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : GuestLink::fromRow($row);
    }

    /**
     * @return list<GuestLink>
     */
    public function forBooking(int $bookingId, bool $includeRevoked = false): array
    {
        $sql = 'SELECT * FROM `guest_link` WHERE `booking_id` = :booking';
        if (!$includeRevoked) {
            $sql .= ' AND `revoked_at` IS NULL';
        }
        $sql .= ' ORDER BY `id` DESC LIMIT 50';

        return array_map(
            static fn (array $row): GuestLink => GuestLink::fromRow($row),
            $this->database->fetchAll($sql, ['booking' => $bookingId])
        );
    }

    public function touch(int $id): void
    {
        $this->database->update('guest_link', ['last_used_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function revoke(int $id): void
    {
        $this->database->update('guest_link', ['revoked_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * Purge les liens expirés depuis longtemps.
     *
     * Un lien caduc n'a plus d'utilité, et conserver son empreinte n'apporte
     * rien (SPECIFICATIONS.md §65, rétention).
     */
    public function purgeExpiredBefore(string $threshold): int
    {
        return $this->database->execute(
            'DELETE FROM `guest_link` WHERE `expires_at` < :threshold',
            ['threshold' => $threshold]
        )->rowCount();
    }
}
