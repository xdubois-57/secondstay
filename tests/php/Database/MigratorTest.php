<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Database\Migrator;
use SecondStay\Tests\Support\DatabaseTestCase;

final class MigratorTest extends DatabaseTestCase
{
    public function testCoreSchemaIsCreated(): void
    {
        $tables = $this->database->tables();

        foreach (['setting', 'user', 'user_session', 'app_log', 'audit_event', 'rate_limit', Migrator::TABLE] as $table) {
            self::assertContains($table, $tables, 'Table manquante : ' . $table);
        }
    }

    public function testMigrationsAreIdempotent(): void
    {
        $migrator = $this->migrator();

        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->migrate());
        self::assertSame([], $migrator->drift());
    }

    public function testCurrentVersionIsRecorded(): void
    {
        self::assertSame('0001', $this->migrator()->currentVersion());

        $applied = $this->migrator()->applied();
        self::assertArrayHasKey('0001', $applied);
        self::assertSame(64, strlen($applied['0001']['checksum']));
    }

    public function testDriftIsDetectedWhenAnAppliedMigrationDisappears(): void
    {
        $this->database->insert(Migrator::TABLE, [
            'version' => '9999',
            'name' => 'ghost',
            'checksum' => str_repeat('0', 64),
            'applied_at' => gmdate('Y-m-d H:i:s'),
            'execution_ms' => 0,
        ]);

        $drift = $this->migrator()->drift();
        self::assertNotSame([], $drift);
        self::assertStringContainsString('9999', $drift[0]);
    }

    public function testUniqueEmailConstraintIsEnforced(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'email' => 'owner@example.test',
            'password_hash' => 'x',
            'role' => 'administrator',
            'locale' => 'fr',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->database->insert('user', $row);

        $this->expectException(\PDOException::class);
        $this->database->insert('user', $row);
    }

    public function testSessionsAreDeletedWithTheirUser(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $userId = $this->database->insert('user', [
            'email' => 'cascade@example.test',
            'password_hash' => 'x',
            'role' => 'customer',
            'locale' => 'fr',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->database->insert('user_session', [
            'id' => str_repeat('a', 64),
            'user_id' => $userId,
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        $this->database->delete('user', ['id' => $userId]);

        self::assertSame(0, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `user_session`'));
    }

    public function testTransactionsRollBackOnFailure(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        /** @var list<string> $observed */
        $observed = [];

        try {
            $this->database->transaction(function () use ($now): void {
                $this->database->insert('user', [
                    'email' => 'rollback@example.test',
                    'password_hash' => 'x',
                    'role' => 'customer',
                    'locale' => 'fr',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                throw new \RuntimeException('échec volontaire');
            });
        } catch (\RuntimeException $exception) {
            $observed[] = $exception->getMessage();
        }

        self::assertSame(['échec volontaire'], $observed, 'La transaction aurait dû échouer.');

        self::assertNull($this->database->fetchOne(
            'SELECT * FROM `user` WHERE `email` = :email',
            ['email' => 'rollback@example.test']
        ));
    }

    public function testNestedTransactionsUseSavepoints(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->database->transaction(function () use ($now): void {
            $this->database->insert('user', [
                'email' => 'outer@example.test',
                'password_hash' => 'x',
                'role' => 'customer',
                'locale' => 'fr',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            try {
                $this->database->transaction(function () use ($now): void {
                    $this->database->insert('user', [
                        'email' => 'inner@example.test',
                        'password_hash' => 'x',
                        'role' => 'customer',
                        'locale' => 'fr',
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    throw new \RuntimeException('échec interne');
                });
            } catch (\RuntimeException) {
                // le point de sauvegarde interne est annulé
            }
        });

        self::assertNotNull($this->database->fetchOne(
            'SELECT * FROM `user` WHERE `email` = :email',
            ['email' => 'outer@example.test']
        ));
        self::assertNull($this->database->fetchOne(
            'SELECT * FROM `user` WHERE `email` = :email',
            ['email' => 'inner@example.test']
        ));
    }
}
