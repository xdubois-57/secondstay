<?php

declare(strict_types=1);

namespace SecondStay\Calendar;

use SecondStay\Database\Database;
use SecondStay\Security\Tokens;

final class CalendarTokenRepository
{
    /** Trente-deux octets : un jeton de calendrier n'expire pas. */
    public const TOKEN_BYTES = 32;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Crée un jeton et renvoie sa valeur en clair, une seule fois.
     *
     * @return array{token: string, id: int}
     */
    public function issue(
        CalendarScope $scope,
        string $label = '',
        ?int $userId = null,
        ?int $bookingId = null,
    ): array {
        $token = Tokens::generateHex(self::TOKEN_BYTES);

        $id = $this->database->insert('calendar_token', [
            'scope' => $scope->value,
            'token_hash' => Tokens::hash($token),
            'label' => mb_substr($label, 0, 120),
            'user_id' => $userId,
            'booking_id' => $bookingId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['token' => $token, 'id' => $id];
    }

    /**
     * Jeton correspondant à une valeur présentée, s'il est encore actif.
     *
     * La recherche porte sur l'empreinte : la valeur en clair n'est jamais
     * comparée à quoi que ce soit de stocké.
     */
    public function findActive(string $token): ?CalendarToken
    {
        if ($token === '') {
            return null;
        }

        $row = $this->database->fetchOne(
            'SELECT * FROM `calendar_token` WHERE `token_hash` = :hash AND `revoked_at` IS NULL',
            ['hash' => Tokens::hash($token)]
        );

        return $row === null ? null : CalendarToken::fromRow($row);
    }

    public function find(int $id): ?CalendarToken
    {
        $row = $this->database->fetchOne('SELECT * FROM `calendar_token` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : CalendarToken::fromRow($row);
    }

    /**
     * Jeton actif d'une portée pour un compte, s'il en existe un.
     */
    public function activeFor(CalendarScope $scope, ?int $userId, ?int $bookingId = null): ?CalendarToken
    {
        $conditions = ['`scope` = :scope', '`revoked_at` IS NULL'];
        $parameters = ['scope' => $scope->value];

        if ($userId === null) {
            $conditions[] = '`user_id` IS NULL';
        } else {
            $conditions[] = '`user_id` = :user';
            $parameters['user'] = $userId;
        }

        if ($bookingId === null) {
            $conditions[] = '`booking_id` IS NULL';
        } else {
            $conditions[] = '`booking_id` = :booking';
            $parameters['booking'] = $bookingId;
        }

        $row = $this->database->fetchOne(
            'SELECT * FROM `calendar_token` WHERE ' . implode(' AND ', $conditions) . ' ORDER BY `id` DESC LIMIT 1',
            $parameters
        );

        return $row === null ? null : CalendarToken::fromRow($row);
    }

    /**
     * @return list<CalendarToken>
     */
    public function all(bool $includeRevoked = false): array
    {
        $sql = 'SELECT * FROM `calendar_token`';
        if (!$includeRevoked) {
            $sql .= ' WHERE `revoked_at` IS NULL';
        }
        $sql .= ' ORDER BY `id` DESC LIMIT 200';

        return array_map(
            static fn (array $row): CalendarToken => CalendarToken::fromRow($row),
            $this->database->fetchAll($sql)
        );
    }

    /**
     * Trace la dernière utilisation, sans faire échouer la lecture du flux.
     */
    public function touch(int $id): void
    {
        $this->database->update('calendar_token', ['last_used_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function revoke(int $id): void
    {
        $this->database->update('calendar_token', ['revoked_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * Révoque tous les jetons d'une portée pour un compte.
     */
    public function revokeAllFor(CalendarScope $scope, ?int $userId, ?int $bookingId = null): int
    {
        $conditions = ['`scope` = :scope', '`revoked_at` IS NULL'];
        $parameters = ['scope' => $scope->value, 'now' => gmdate('Y-m-d H:i:s')];

        if ($userId === null) {
            $conditions[] = '`user_id` IS NULL';
        } else {
            $conditions[] = '`user_id` = :user';
            $parameters['user'] = $userId;
        }

        if ($bookingId !== null) {
            $conditions[] = '`booking_id` = :booking';
            $parameters['booking'] = $bookingId;
        }

        return $this->database->execute(
            'UPDATE `calendar_token` SET `revoked_at` = :now WHERE ' . implode(' AND ', $conditions),
            $parameters
        )->rowCount();
    }
}
