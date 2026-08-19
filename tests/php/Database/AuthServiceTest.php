<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\AuthService;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\Role;
use SecondStay\Auth\SessionRepository;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Core\Session;
use SecondStay\Logging\Logger;
use SecondStay\Security\RateLimiter;
use SecondStay\Tests\Support\DatabaseTestCase;

final class AuthServiceTest extends DatabaseTestCase
{
    private UserRepository $users;

    private SessionRepository $sessions;

    private Session $session;

    private AuthService $auth;

    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->database);
        $this->sessions = new SessionRepository($this->database);
        $this->session = new Session();
        $this->session->start();
        $this->session->regenerate();
        $this->hasher = new PasswordHasher();

        $this->auth = new AuthService(
            $this->users,
            $this->sessions,
            $this->session,
            $this->hasher,
            new RateLimiter($this->database),
            (new Logger($this->storagePath . '/logs'))->withDatabase($this->database),
            new AuditTrail($this->database),
        );
    }

    private function createAdmin(string $email = 'admin@example.test', string $password = 'Marée-Haute-2026!'): int
    {
        return $this->users->create(
            $email,
            $this->hasher->hash($password),
            'Claire',
            'Dubois',
            '+33600000000',
            Role::Administrator,
            'fr',
            UserStatus::Active,
        );
    }

    public function testSuccessfulLogin(): void
    {
        $id = $this->createAdmin();

        $result = $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        self::assertTrue($result['ok']);
        self::assertSame($id, $result['user']->id);
        self::assertTrue($this->auth->isAdministrator());
        self::assertCount(1, $this->sessions->activeForUser($id));
    }

    public function testEmailIsCaseInsensitive(): void
    {
        $this->createAdmin();

        $result = $this->auth->attempt('ADMIN@Example.TEST', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');
        self::assertTrue($result['ok']);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->createAdmin();

        $result = $this->auth->attempt('admin@example.test', 'wrong-password', '203.0.113.5', 'PHPUnit');

        self::assertFalse($result['ok']);
        self::assertSame('auth.login.invalid_credentials', $result['error']);
        self::assertFalse($this->auth->isAuthenticated());
    }

    public function testUnknownAccountReturnsTheSameErrorAsAWrongPassword(): void
    {
        $result = $this->auth->attempt('nobody@example.test', 'whatever-1234', '203.0.113.5', 'PHPUnit');

        self::assertFalse($result['ok']);
        self::assertSame('auth.login.invalid_credentials', $result['error']);
    }

    public function testSuspendedAccountCannotAuthenticate(): void
    {
        $id = $this->createAdmin();
        $this->users->update($id, ['status' => UserStatus::Suspended->value]);

        $result = $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        self::assertFalse($result['ok']);
        self::assertSame('auth.login.account_suspended', $result['error']);
    }

    public function testRateLimitingBlocksBruteForce(): void
    {
        $this->createAdmin();

        for ($attempt = 0; $attempt < AuthService::MAX_LOGIN_ATTEMPTS; $attempt++) {
            $this->auth->attempt('admin@example.test', 'nope-' . $attempt, '203.0.113.5', 'PHPUnit');
        }

        $result = $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        self::assertFalse($result['ok']);
        self::assertSame('auth.login.rate_limited', $result['error']);
    }

    public function testAnAdministratorCanClearRateLimitsToUnlockAnAccount(): void
    {
        $this->createAdmin();

        for ($attempt = 0; $attempt < AuthService::MAX_LOGIN_ATTEMPTS; $attempt++) {
            $this->auth->attempt('admin@example.test', 'nope-' . $attempt, '203.0.113.5', 'PHPUnit');
        }
        self::assertFalse($this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit')['ok']);

        $removed = (new RateLimiter($this->database))->clearAll();
        self::assertGreaterThan(0, $removed);
        self::assertSame(0, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `rate_limit`'));

        // Le bon mot de passe fonctionne de nouveau immédiatement.
        self::assertTrue($this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit')['ok']);
    }

    public function testRevokedSessionEndsAccessImmediately(): void
    {
        $id = $this->createAdmin();
        $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        $this->sessions->revokeAllForUser($id);

        $fresh = new AuthService(
            $this->users,
            $this->sessions,
            $this->session,
            $this->hasher,
            new RateLimiter($this->database),
            new Logger($this->storagePath . '/logs'),
        );

        self::assertNull($fresh->user());
        self::assertFalse($this->session->has(AuthService::SESSION_USER_KEY));
    }

    public function testLogoutRevokesTheCurrentSession(): void
    {
        $id = $this->createAdmin();
        $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        $this->auth->logout();

        self::assertFalse($this->auth->isAuthenticated());
        self::assertSame([], $this->sessions->activeForUser($id));
    }

    public function testSessionIdIsRotatedOnLogin(): void
    {
        $this->createAdmin();
        $before = $this->session->id();

        $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        self::assertNotSame($before, $this->session->id());
    }

    public function testActiveSessionsDoNotLeakTheToken(): void
    {
        $this->createAdmin();
        $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        $sessions = $this->auth->activeSessions();

        self::assertCount(1, $sessions);
        self::assertArrayNotHasKey('id', $sessions[0]);
        self::assertTrue($sessions[0]['is_current']);
    }

    public function testRevokeOtherSessionsKeepsTheCurrentOne(): void
    {
        $id = $this->createAdmin();
        $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        $this->sessions->create(str_repeat('b', 64), $id, 120, '198.51.100.9', 'Autre appareil');
        self::assertCount(2, $this->sessions->activeForUser($id));

        self::assertSame(1, $this->auth->revokeOtherSessions());
        self::assertCount(1, $this->sessions->activeForUser($id));
    }

    public function testExpiredSessionIsRefused(): void
    {
        $id = $this->createAdmin();
        $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        $this->database->execute(
            'UPDATE `user_session` SET `expires_at` = :past',
            ['past' => gmdate('Y-m-d H:i:s', time() - 3600)]
        );

        $fresh = new AuthService(
            $this->users,
            $this->sessions,
            $this->session,
            $this->hasher,
            new RateLimiter($this->database),
            new Logger($this->storagePath . '/logs'),
        );

        self::assertNull($fresh->user());
    }

    public function testRoleHierarchy(): void
    {
        $this->createAdmin();
        $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        self::assertTrue($this->auth->hasRole(Role::Customer));
        self::assertTrue($this->auth->hasRole(Role::LocalManager));
        self::assertTrue($this->auth->hasRole(Role::Administrator));
        self::assertTrue($this->auth->isOperational());
    }

    public function testLoginIsAudited(): void
    {
        $this->createAdmin();
        $this->auth->attempt('admin@example.test', 'Marée-Haute-2026!', '203.0.113.5', 'PHPUnit');

        $events = (new AuditTrail($this->database))->recent();
        self::assertSame('auth.login', $events[0]['action']);
    }

    public function testPasswordIsNeverStoredInClearText(): void
    {
        $this->createAdmin();

        $hash = (string) $this->database->fetchValue('SELECT `password_hash` FROM `user` LIMIT 1');
        self::assertStringNotContainsString('Marée', $hash);
        self::assertTrue(password_verify('Marée-Haute-2026!', $hash));
    }
}
