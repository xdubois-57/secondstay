<?php

declare(strict_types=1);

namespace SecondStay\Auth;

use SecondStay\Audit\AuditTrail;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\I18n\Locales;
use SecondStay\Legal\LegalDocumentType;
use SecondStay\Legal\LegalService;
use SecondStay\Logging\Logger;
use SecondStay\Mail\MailAddress;
use SecondStay\Mail\MailService;
use SecondStay\Security\RateLimiter;
use SensitiveParameter;
use Throwable;

/**
 * Cycle de vie d'un compte client : inscription, confirmation, réinitialisation,
 * préférences, export et suppression RGPD.
 */
final class AccountService
{
    /**
     * Types de consentement recueillis à l'inscription.
     *
     * Ils reprennent les valeurs de `LegalDocumentType` : le même texte porte
     * le même nom, qu'il soit accepté à l'inscription ou à la réservation.
     */
    public const CONSENT_TERMS = 'terms';
    public const CONSENT_PRIVACY = 'privacy';

    public const SIGNUP_MAX_PER_IP = 5;
    public const RESET_MAX_PER_ACCOUNT = 5;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly SessionRepository $sessions,
        private readonly ConsentRepository $consents,
        private readonly PasswordHasher $hasher,
        private readonly MailService $mail,
        private readonly RateLimiter $rateLimiter,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
        private readonly ?LegalService $legal = null,
    ) {
    }

    /**
     * Inscription minimale : prénom, nom, e-mail, téléphone
     * (SPECIFICATIONS.md §10).
     *
     * @param array<string, mixed> $input
     *
     * @return array{user_id: int, mail_sent: bool}
     *
     * @throws ValidationException
     */
    public function register(array $input, string $ip = '', string $locale = Locales::FALLBACK): array
    {
        $limit = $this->rateLimiter->hit('signup:ip:' . $ip, self::SIGNUP_MAX_PER_IP);
        if (!$limit['allowed']) {
            throw new ValidationException(['general' => 'account.error.rate_limited']);
        }

        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $locale = Locales::normalise((string) ($input['locale'] ?? $locale)) ?? $locale;

        $errors = [];
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'account.error.email_invalid';
        }
        if ($firstName === '') {
            $errors['first_name'] = 'account.error.required';
        }
        if ($lastName === '') {
            $errors['last_name'] = 'account.error.required';
        }
        if ($phone !== '' && preg_match('/^[+0-9 ().-]{6,40}$/', $phone) !== 1) {
            $errors['phone'] = 'account.error.phone_invalid';
        }

        $evaluation = $this->hasher->evaluate($password);
        if ($evaluation['errors'] !== []) {
            $errors['password'] = $evaluation['errors'][0];
        }

        if (($input['accept_terms'] ?? null) === null) {
            $errors['accept_terms'] = 'account.error.terms_required';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            // On ne révèle jamais qu'un compte existe : le parcours se termine
            // de la même façon, mais on informe le titulaire réel.
            $this->logger->info('account', 'Inscription sur une adresse déjà connue');
            $this->sendExistingAccountNotice($existing);

            return ['user_id' => 0, 'mail_sent' => true];
        }

        $userId = $this->users->create(
            $email,
            $this->hasher->hash($password),
            $firstName,
            $lastName,
            $phone,
            Role::Customer,
            $locale,
            UserStatus::Pending,
        );

        // La version acceptée est celle réellement publiée : enregistrer un
        // « 1 » constant reviendrait à ne rien enregistrer du tout
        // (SPECIFICATIONS.md §65).
        foreach (LegalDocumentType::acceptedOnSignup() as $type) {
            $document = $this->legal?->current($type, $locale);

            $this->consents->record(
                $userId,
                $type->value,
                $document === null ? LegalService::INITIAL_VERSION : $document->version,
                $document === null ? $locale : $document->locale,
                $ip,
            );
        }

        $token = $this->tokens->issue($userId, TokenType::EmailConfirmation, $ip);
        $result = $this->mail->send('account_confirmation', new MailAddress($email, trim($firstName . ' ' . $lastName)), $locale, [
            'first_name' => $firstName,
            'confirmation_token' => $token,
            'confirmation_path' => '/' . $locale . '/account/confirm?token=' . rawurlencode($token),
        ], $userId);

        $this->audit?->record('account.registered', 'user', (string) $userId, null, ['locale' => $locale], $userId, $email);
        $this->logger->info('account', 'Compte créé', ['user_id' => $userId, 'locale' => $locale]);

        return ['user_id' => $userId, 'mail_sent' => $result['ok']];
    }

    /**
     * @return array{ok: bool, user: ?User, error: string}
     */
    public function confirmEmail(string $token): array
    {
        $record = $this->tokens->findValid($token, TokenType::EmailConfirmation);
        if ($record === null) {
            return ['ok' => false, 'user' => null, 'error' => 'account.error.token_invalid'];
        }

        $userId = (int) $record['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return ['ok' => false, 'user' => null, 'error' => 'account.error.token_invalid'];
        }

        $this->tokens->consume((int) $record['id']);

        if ($user->status === UserStatus::Pending) {
            $this->users->update($userId, [
                'status' => UserStatus::Active->value,
                'email_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $this->audit?->record('account.confirmed', 'user', (string) $userId, null, null, $userId, $user->email);

        return ['ok' => true, 'user' => $this->users->findById($userId), 'error' => ''];
    }

    /**
     * Demande de réinitialisation. La réponse est toujours la même, qu'un
     * compte existe ou non.
     */
    public function requestPasswordReset(string $email, string $ip = '', string $locale = Locales::FALLBACK): void
    {
        $normalised = mb_strtolower(trim($email));
        $user = $this->users->findByEmail($normalised);

        if ($user === null) {
            $this->logger->info('account', 'Réinitialisation demandée pour une adresse inconnue');

            return;
        }

        $limit = $this->rateLimiter->hit('reset:user:' . $user->id, self::RESET_MAX_PER_ACCOUNT);
        if (!$limit['allowed']) {
            $this->logger->warning('account', 'Trop de demandes de réinitialisation', ['user_id' => $user->id]);

            return;
        }

        $token = $this->tokens->issue($user->id, TokenType::PasswordReset, $ip);
        $effectiveLocale = Locales::isSupported($user->locale) ? $user->locale : $locale;

        $this->mail->send('password_reset', new MailAddress($user->email, $user->displayName()), $effectiveLocale, [
            'first_name' => $user->firstName,
            'reset_path' => '/' . $effectiveLocale . '/account/reset?token=' . rawurlencode($token),
            'expires_in_hours' => (int) (TokenType::PasswordReset->lifetimeSeconds() / 3600),
        ], $user->id);

        $this->audit?->record('account.reset_requested', 'user', (string) $user->id, null, null, $user->id, $user->email);
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function resetPassword(string $token, #[SensitiveParameter] string $password): array
    {
        $record = $this->tokens->findValid($token, TokenType::PasswordReset);
        if ($record === null) {
            return ['ok' => false, 'error' => 'account.error.token_invalid'];
        }

        $evaluation = $this->hasher->evaluate($password);
        if ($evaluation['errors'] !== []) {
            return ['ok' => false, 'error' => $evaluation['errors'][0]];
        }

        $userId = (int) $record['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return ['ok' => false, 'error' => 'account.error.token_invalid'];
        }

        $this->tokens->consume((int) $record['id']);
        $this->users->updatePasswordHash($userId, $this->hasher->hash($password));

        if ($user->status === UserStatus::Pending) {
            // Un lien de réinitialisation atteint prouve la possession de la
            // boîte mail : le compte peut être activé.
            $this->users->update($userId, [
                'status' => UserStatus::Active->value,
                'email_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        // Toutes les sessions existantes sont invalidées.
        $this->sessions->revokeAllForUser($userId);

        $this->audit?->record('account.password_reset', 'user', (string) $userId, null, null, $userId, $user->email);
        $this->logger->info('account', 'Mot de passe réinitialisé', ['user_id' => $userId]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function changePassword(
        User $user,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword,
        string $currentSessionHash = '',
    ): array {
        if ($user->passwordHash === null || !$this->hasher->verify($currentPassword, $user->passwordHash)) {
            return ['ok' => false, 'error' => 'account.error.current_password'];
        }

        $evaluation = $this->hasher->evaluate($newPassword);
        if ($evaluation['errors'] !== []) {
            return ['ok' => false, 'error' => $evaluation['errors'][0]];
        }

        $this->users->updatePasswordHash($user->id, $this->hasher->hash($newPassword));
        $this->sessions->revokeAllForUser($user->id, $currentSessionHash === '' ? null : $currentSessionHash);

        $this->audit?->record('account.password_changed', 'user', (string) $user->id, null, null, $user->id, $user->email);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationException
     */
    public function updateProfile(User $user, array $input): void
    {
        $firstName = trim((string) ($input['first_name'] ?? $user->firstName));
        $lastName = trim((string) ($input['last_name'] ?? $user->lastName));
        $phone = trim((string) ($input['phone'] ?? $user->phone));
        $locale = Locales::normalise((string) ($input['locale'] ?? $user->locale)) ?? $user->locale;

        $errors = [];
        if ($firstName === '') {
            $errors['first_name'] = 'account.error.required';
        }
        if ($lastName === '') {
            $errors['last_name'] = 'account.error.required';
        }
        if ($phone !== '' && preg_match('/^[+0-9 ().-]{6,40}$/', $phone) !== 1) {
            $errors['phone'] = 'account.error.phone_invalid';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->users->update($user->id, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'locale' => $locale,
        ]);

        $this->audit?->record(
            'account.profile_updated',
            'user',
            (string) $user->id,
            ['locale' => $user->locale],
            ['locale' => $locale],
            $user->id,
            $user->email
        );
    }

    /**
     * Export RGPD : toutes les données personnelles connues du compte.
     *
     * @return array<string, mixed>
     */
    public function exportData(User $user): array
    {
        $sessions = array_map(
            static function (array $session): array {
                unset($session['id']);

                return $session;
            },
            $this->sessions->activeForUser($user->id)
        );

        return [
            'exported_at' => gmdate('c'),
            'account' => [
                'email' => $user->email,
                'first_name' => $user->firstName,
                'last_name' => $user->lastName,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'role' => $user->role->value,
                'status' => $user->status->value,
                'email_verified_at' => $user->emailVerifiedAt,
                'last_login_at' => $user->lastLoginAt,
            ],
            'consents' => $this->consents->forUser($user->id),
            'active_sessions' => $sessions,
        ];
    }

    /**
     * Suppression RGPD : le compte est anonymisé plutôt que supprimé, afin de
     * préserver l'intégrité comptable et contractuelle. Les données
     * directement identifiantes disparaissent.
     */
    public function anonymise(User $user, ?string $actorLabel = null, ?int $actorId = null): void
    {
        $placeholder = 'supprime+' . $user->id . '@invalid.local';

        $this->sessions->revokeAllForUser($user->id);

        $this->users->update($user->id, [
            'email' => $placeholder,
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'password_hash' => null,
            'status' => UserStatus::Suspended->value,
            'anonymised_at' => gmdate('Y-m-d H:i:s'),
            'deletion_requested_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->audit?->record(
            'account.anonymised',
            'user',
            (string) $user->id,
            null,
            null,
            $actorId ?? $user->id,
            $actorLabel ?? 'self-service'
        );
        $this->logger->info('account', 'Compte anonymisé', ['user_id' => $user->id]);
    }

    private function sendExistingAccountNotice(User $user): void
    {
        try {
            $this->mail->send('account_exists', new MailAddress($user->email, $user->displayName()), $user->locale, [
                'first_name' => $user->firstName,
                'reset_path' => '/' . $user->locale . '/account/forgot-password',
            ], $user->id);
        } catch (Throwable $throwable) {
            $this->logger->error('account', 'Notification de compte existant impossible', [
                'reason' => $throwable->getMessage(),
            ]);
        }
    }
}
