<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\Role;
use SecondStay\Auth\UserStatus;
use SecondStay\Core\Session;
use SecondStay\Logging\LogSanitizer;
use SecondStay\Security\Csrf;
use SecondStay\Security\Tokens;

final class SecurityPrimitivesTest extends TestCase
{
    public function testCsrfTokenIsStableThenRotatable(): void
    {
        $session = new Session();
        /** @var array<string, mixed> $reference */
        $reference = &$session->reference();
        $csrf = new Csrf($reference);

        $token = $csrf->token();
        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->isValid($token));

        $rotated = $csrf->rotate();
        self::assertNotSame($token, $rotated);
        self::assertFalse($csrf->isValid($token));
        self::assertTrue($csrf->isValid($rotated));
    }

    public function testCsrfRejectsEmptyAndWrongTokens(): void
    {
        $session = new Session();
        /** @var array<string, mixed> $reference */
        $reference = &$session->reference();
        $csrf = new Csrf($reference);
        $csrf->token();

        self::assertFalse($csrf->isValid(null));
        self::assertFalse($csrf->isValid(''));
        self::assertFalse($csrf->isValid('mauvais-jeton'));
    }

    public function testTokensAreLongAndUnique(): void
    {
        $first = Tokens::generate();
        $second = Tokens::generate();

        self::assertNotSame($first, $second);
        self::assertGreaterThanOrEqual(40, strlen($first));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $first);
    }

    public function testTokensAreStoredHashed(): void
    {
        $token = Tokens::generate();
        $hash = Tokens::hash($token);

        self::assertNotSame($token, $hash);
        self::assertSame(64, strlen($hash));
        self::assertTrue(Tokens::equals($hash, Tokens::hash($token)));
        self::assertFalse(Tokens::equals($hash, Tokens::hash(Tokens::generate())));
    }

    public function testBookingReferenceIsReadable(): void
    {
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', Tokens::reference());
        self::assertStringStartsWith('SS-', Tokens::reference('SS'));
    }

    public function testPasswordHashingIsIrreversible(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('Marée-Haute-2026!');

        self::assertNotSame('Marée-Haute-2026!', $hash);
        self::assertTrue($hasher->verify('Marée-Haute-2026!', $hash));
        self::assertFalse($hasher->verify('autre', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testPasswordEvaluationMatchesTheClientRules(): void
    {
        $hasher = new PasswordHasher();

        self::assertContains('auth.password.too_short', $hasher->evaluate('Abc1')['errors']);
        self::assertContains('auth.password.needs_uppercase', $hasher->evaluate('abcdefghijkl1')['errors']);
        self::assertContains('auth.password.needs_digit', $hasher->evaluate('Abcdefghijklm')['errors']);
        self::assertContains('auth.password.too_repetitive', $hasher->evaluate('AAAAaaaa1111')['errors']);
        self::assertSame([], $hasher->evaluate('Marée-Haute-2026!')['errors']);
        self::assertGreaterThanOrEqual(90, $hasher->evaluate('Marée-Haute-2026!')['score']);
    }

    public function testRoleHierarchy(): void
    {
        self::assertTrue(Role::Administrator->includes(Role::LocalManager));
        self::assertTrue(Role::Administrator->includes(Role::Customer));
        self::assertTrue(Role::LocalManager->includes(Role::Customer));
        self::assertFalse(Role::LocalManager->includes(Role::Administrator));
        self::assertFalse(Role::Customer->includes(Role::LocalManager));

        self::assertTrue(Role::Administrator->isOperational());
        self::assertTrue(Role::LocalManager->isOperational());
        self::assertFalse(Role::Customer->isOperational());

        self::assertSame(Role::Customer, Role::fromString('inconnu'));
    }

    public function testUserStatusGate(): void
    {
        self::assertTrue(UserStatus::Active->canAuthenticate());
        self::assertFalse(UserStatus::Pending->canAuthenticate());
        self::assertFalse(UserStatus::Suspended->canAuthenticate());
        self::assertSame(UserStatus::Pending, UserStatus::fromString('inconnu'));
    }

    public function testLogSanitizerRemovesSecrets(): void
    {
        $clean = LogSanitizer::sanitize([
            'password' => 'hunter2',
            'api_key' => 'live_abcdefghijklmnopqrstuvwx',
            'nested' => ['smtp_password' => 'x', 'ok' => 'visible'],
            'note' => 'Bearer abcdefghijklmnop',
            'count' => 3,
        ]);

        self::assertSame('***', $clean['password']);
        self::assertSame('***', $clean['api_key']);
        self::assertSame('***', $clean['nested']['smtp_password']);
        self::assertSame('visible', $clean['nested']['ok']);
        self::assertSame('Bearer ***', $clean['note']);
        self::assertSame(3, $clean['count']);
    }

    public function testLogSanitizerRedactsPrivateKeys(): void
    {
        $value = "-----BEGIN RSA PRIVATE KEY-----\nabcdef\n-----END RSA PRIVATE KEY-----";

        self::assertSame('***', LogSanitizer::redactPatterns($value));
    }

    public function testSessionFlashesAreConsumedOnce(): void
    {
        $session = new Session();
        $session->flash('success', 'admin.settings.saved');

        self::assertCount(1, $session->takeFlashes());
        self::assertSame([], $session->takeFlashes());
    }
}
