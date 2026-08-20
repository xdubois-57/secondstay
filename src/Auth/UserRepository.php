<?php

declare(strict_types=1);

namespace SecondStay\Auth;

use SecondStay\Database\Database;

final class UserRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findById(int $id): ?User
    {
        $row = $this->database->fetchOne('SELECT * FROM `user` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : User::fromRow($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `user` WHERE `email` = :email',
            ['email' => mb_strtolower(trim($email))]
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    public function create(
        string $email,
        ?string $passwordHash,
        string $firstName,
        string $lastName,
        string $phone,
        Role $role,
        string $locale,
        UserStatus $status,
    ): int {
        $now = gmdate('Y-m-d H:i:s');

        return $this->database->insert('user', [
            'email' => mb_strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'role' => $role->value,
            'locale' => $locale,
            'status' => $status->value,
            'email_verified_at' => $status === UserStatus::Active ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->database->update('user', $data, ['id' => $id]);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->update($id, ['password_hash' => $hash]);
    }

    public function markLogin(int $id): void
    {
        $this->update($id, ['last_login_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function delete(int $id): void
    {
        $this->database->delete('user', ['id' => $id]);
    }

    /**
     * @return list<User>
     */
    public function findByRole(Role $role): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM `user` WHERE `role` = :role ORDER BY `email`',
            ['role' => $role->value]
        );

        return array_map(static fn (array $row): User => User::fromRow($row), $rows);
    }

    /**
     * @return list<User>
     */
    public function all(int $limit = 200): array
    {
        $limit = max(1, min($limit, 1000));
        $rows = $this->database->fetchAll(
            sprintf('SELECT * FROM `user` ORDER BY `role` DESC, `email` LIMIT %d', $limit)
        );

        return array_map(static fn (array $row): User => User::fromRow($row), $rows);
    }

    /**
     * Comptes pouvant être responsables d'un séjour.
     *
     * Un administrateur hérite du rôle opérationnel (SPECIFICATIONS.md §4) :
     * il peut donc être responsable, mais un client ne le peut jamais.
     *
     * @return list<User>
     */
    public function operational(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM `user` WHERE `role` IN (:manager, :admin) AND `status` = :status '
            . 'ORDER BY `last_name`, `first_name`, `email` LIMIT 200',
            [
                'manager' => Role::LocalManager->value,
                'admin' => Role::Administrator->value,
                'status' => UserStatus::Active->value,
            ]
        );

        return array_map(static fn (array $row): User => User::fromRow($row), $rows);
    }

    public function countAdministrators(): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `user` WHERE `role` = :role AND `status` = :status',
            ['role' => Role::Administrator->value, 'status' => UserStatus::Active->value]
        );
    }

    public function hasAnyUser(): bool
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM `user`') > 0;
    }
}
