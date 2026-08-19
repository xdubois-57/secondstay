<?php

declare(strict_types=1);

namespace SecondStay\Auth;

use SecondStay\Audit\AuditTrail;
use SecondStay\Core\Session;
use SecondStay\Logging\Logger;
use SecondStay\Security\RateLimiter;
use SecondStay\Security\Tokens;
use SensitiveParameter;

/**
 * Authentification par session.
 *
 * L'identifiant de session PHP est régénéré après authentification, et une
 * ligne `user_session` permet de lister et révoquer les appareils.
 */
final class AuthService
{
    public const SESSION_USER_KEY = '_user_id';
    public const MAX_LOGIN_ATTEMPTS = 10;

    /**
     * Hash factice comparé lorsqu'aucun compte ne correspond : le temps de
     * réponse ne révèle pas l'existence de l'adresse.
     */
    private const DUMMY_HASH = '$2y$12$............................................................';

    private ?User $current = null;

    private bool $resolved = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly Session $session,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $rateLimiter,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
        private readonly int $sessionLifetimeMinutes = 120,
    ) {
    }

    /**
     * @return array{ok: true, user: User}|array{ok: false, error: string, retry_after?: int}
     */
    public function attempt(
        string $email,
        #[SensitiveParameter] string $password,
        string $ip,
        string $userAgent,
    ): array {
        $normalisedEmail = mb_strtolower(trim($email));

        $ipLimit = $this->rateLimiter->hit('login:ip:' . $ip, self::MAX_LOGIN_ATTEMPTS * 3);
        $accountLimit = $this->rateLimiter->hit('login:email:' . $normalisedEmail, self::MAX_LOGIN_ATTEMPTS);

        if (!$ipLimit['allowed'] || !$accountLimit['allowed']) {
            $this->logger->warning('auth', 'Trop de tentatives de connexion', ['ip' => $ip]);

            return [
                'ok' => false,
                'error' => 'auth.login.rate_limited',
                'retry_after' => max($ipLimit['retry_after'], $accountLimit['retry_after']),
            ];
        }

        $user = $this->users->findByEmail($normalisedEmail);

        // Comparaison systématique : pas de canal auxiliaire révélant
        // l'existence d'un compte.
        $hash = $user !== null && $user->passwordHash !== null
            ? $user->passwordHash
            : self::DUMMY_HASH;
        $valid = $this->hasher->verify($password, $hash);

        if ($user === null || !$valid) {
            $this->logger->info('auth', 'Échec de connexion', ['email' => $normalisedEmail, 'ip' => $ip]);

            return ['ok' => false, 'error' => 'auth.login.invalid_credentials'];
        }

        if (!$user->status->canAuthenticate()) {
            return ['ok' => false, 'error' => 'auth.login.account_' . $user->status->value];
        }

        if ($user->passwordHash !== null && $this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->startSession($user, $ip, $userAgent);
        $this->rateLimiter->reset('login:email:' . $normalisedEmail);

        $this->logger->info('auth', 'Connexion réussie', ['user_id' => $user->id]);
        $this->audit?->record('auth.login', 'user', (string) $user->id, null, null, $user->id, $user->email);

        return ['ok' => true, 'user' => $user];
    }

    public function startSession(User $user, string $ip, string $userAgent): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_USER_KEY, $user->id);

        $this->sessions->create(
            Tokens::hash($this->session->id()),
            $user->id,
            $this->sessionLifetimeMinutes,
            $ip,
            $userAgent
        );

        $this->users->markLogin($user->id);
        $this->current = $user;
        $this->resolved = true;
    }

    public function logout(): void
    {
        $user = $this->user();
        $sessionId = $this->session->id();
        if ($sessionId !== '') {
            $this->sessions->revoke(Tokens::hash($sessionId));
        }

        if ($user !== null) {
            $this->audit?->record('auth.logout', 'user', (string) $user->id, null, null, $user->id, $user->email);
        }

        $this->session->clear();
        $this->current = null;
        $this->resolved = true;
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->current;
        }

        $this->resolved = true;
        $userId = $this->session->int(self::SESSION_USER_KEY);
        if ($userId === null) {
            return null;
        }

        $sessionId = $this->session->id();
        if ($sessionId === '') {
            return null;
        }

        $tokenHash = Tokens::hash($sessionId);
        $row = $this->sessions->findActive($tokenHash);
        if ($row === null) {
            // Session révoquée ou expirée : l'accès cesse immédiatement.
            $this->session->clear();

            return null;
        }

        $user = $this->users->findById($userId);
        if ($user === null || !$user->status->canAuthenticate()) {
            $this->session->clear();

            return null;
        }

        $this->sessions->touch($tokenHash, $this->sessionLifetimeMinutes);
        $this->current = $user;
        $this->logger->setUserId($user->id);

        return $user;
    }

    public function isAuthenticated(): bool
    {
        return $this->user() !== null;
    }

    public function isAdministrator(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    public function isOperational(): bool
    {
        return $this->user()?->isOperational() ?? false;
    }

    public function hasRole(Role $role): bool
    {
        $user = $this->user();

        return $user !== null && $user->role->includes($role);
    }

    /**
     * Déconnecte tous les autres appareils.
     */
    public function revokeOtherSessions(): int
    {
        $user = $this->user();
        if ($user === null) {
            return 0;
        }

        $count = $this->sessions->revokeAllForUser($user->id, Tokens::hash($this->session->id()));
        $this->audit?->record('auth.sessions_revoked', 'user', (string) $user->id, null, ['count' => $count], $user->id, $user->email);

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeSessions(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        $current = Tokens::hash($this->session->id());

        return array_map(
            static function (array $row) use ($current): array {
                $row['is_current'] = ($row['id'] ?? '') === $current;
                unset($row['id']);

                return $row;
            },
            $this->sessions->activeForUser($user->id)
        );
    }

    public function revokeSessionByIndex(int $index): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $sessions = $this->sessions->activeForUser($user->id);
        if (!isset($sessions[$index])) {
            return false;
        }

        $this->sessions->revoke((string) $sessions[$index]['id']);

        return true;
    }
}
