<?php

declare(strict_types=1);

namespace SecondStay\Auth;

use SecondStay\Database\Database;
use SecondStay\Security\Tokens;

/**
 * Jetons à usage unique (confirmation d'e-mail, réinitialisation).
 *
 * Seul le hash est stocké : une fuite de base ne permet pas de rejouer un
 * jeton (SECURITY.md §15).
 */
final class TokenRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return string jeton en clair, à transmettre une seule fois
     */
    public function issue(int $userId, TokenType $type, string $ip = ''): string
    {
        // Un nouveau jeton invalide les précédents du même type.
        $this->database->execute(
            'UPDATE `user_token` SET `used_at` = :now WHERE `user_id` = :user AND `type` = :type AND `used_at` IS NULL',
            ['now' => gmdate('Y-m-d H:i:s'), 'user' => $userId, 'type' => $type->value]
        );

        $token = Tokens::generate();

        $this->database->insert('user_token', [
            'user_id' => $userId,
            'type' => $type->value,
            'token_hash' => Tokens::hash($token),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $type->lifetimeSeconds()),
            'ip' => mb_substr($ip, 0, 45),
        ]);

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findValid(string $token, TokenType $type): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM `user_token` WHERE `token_hash` = :hash AND `type` = :type '
            . 'AND `used_at` IS NULL AND `expires_at` > :now',
            [
                'hash' => Tokens::hash($token),
                'type' => $type->value,
                'now' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    public function consume(int $tokenId): void
    {
        $this->database->update('user_token', ['used_at' => gmdate('Y-m-d H:i:s')], ['id' => $tokenId]);
    }

    public function purgeExpired(): int
    {
        return $this->database->execute(
            'DELETE FROM `user_token` WHERE `expires_at` < :threshold',
            ['threshold' => gmdate('Y-m-d H:i:s', time() - 86400 * 30)]
        )->rowCount();
    }

    public function countActive(int $userId, TokenType $type): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `user_token` WHERE `user_id` = :user AND `type` = :type '
            . 'AND `used_at` IS NULL AND `expires_at` > :now',
            ['user' => $userId, 'type' => $type->value, 'now' => gmdate('Y-m-d H:i:s')]
        );
    }
}
