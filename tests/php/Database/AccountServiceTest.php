<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\AccountService;
use SecondStay\Auth\ConsentRepository;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\Role;
use SecondStay\Auth\SessionRepository;
use SecondStay\Auth\TokenRepository;
use SecondStay\Auth\TokenType;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Router;
use SecondStay\Core\Routes;
use SecondStay\Core\View;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Logging\Logger;
use SecondStay\Mail\FakeMailTransport;
use SecondStay\Mail\MailRepository;
use SecondStay\Mail\MailService;
use SecondStay\Security\Encryptor;
use SecondStay\Security\RateLimiter;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Cycle de vie complet d'un compte client (SPECIFICATIONS.md §10).
 *
 * Le transport d'e-mail est factice : le parcours entier est vérifiable sans
 * serveur SMTP, conformément à TESTING.md §8.
 */
final class AccountServiceTest extends DatabaseTestCase
{
    private AccountService $accounts;

    private UserRepository $users;

    private TokenRepository $tokens;

    private SessionRepository $sessions;

    private ConsentRepository $consents;

    private FakeMailTransport $transport;

    private MailService $mail;

    private PasswordHasher $hasher;

    private const PASSWORD = 'Marée-Haute-2026!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->database);
        $this->tokens = new TokenRepository($this->database);
        $this->sessions = new SessionRepository($this->database);
        $this->consents = new ConsentRepository($this->database);
        $this->hasher = new PasswordHasher();
        $this->transport = new FakeMailTransport($this->storagePath . '/temp/mail');

        $router = new Router();
        Routes::register($router);

        $translator = new Translator(self::projectRoot() . '/translations', 'fr');
        $view = new View(self::projectRoot() . '/templates', $translator, new Formatter(), $router);

        $settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $settings->setMany([
            'mail.from_address' => 'noreply@example.test',
            'mail.from_name' => 'Villa Test',
            'property.name' => 'Villa Test',
        ]);

        $logger = (new Logger($this->storagePath . '/logs'))->withDatabase($this->database);

        $this->mail = new MailService(
            $this->transport,
            $view,
            $translator,
            $settings,
            new MailRepository($this->database),
            $logger,
        );

        $this->accounts = new AccountService(
            $this->users,
            $this->tokens,
            $this->sessions,
            $this->consents,
            $this->hasher,
            $this->mail,
            new RateLimiter($this->database),
            $logger,
            new AuditTrail($this->database),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function signupInput(array $overrides = []): array
    {
        return $overrides + [
            'email' => 'claire@example.test',
            'password' => self::PASSWORD,
            'first_name' => 'Claire',
            'last_name' => 'Dubois',
            'phone' => '+33600000000',
            'locale' => 'fr',
            'accept_terms' => '1',
        ];
    }

    private function findUser(string $email): User
    {
        $user = $this->users->findByEmail($email);
        self::assertNotNull($user);

        return $user;
    }

    // --- Inscription -----------------------------------------------------

    public function testRegistrationCreatesAPendingCustomerAndSendsAConfirmation(): void
    {
        $result = $this->accounts->register($this->signupInput(), '203.0.113.10', 'fr');

        self::assertGreaterThan(0, $result['user_id']);
        self::assertTrue($result['mail_sent']);

        $user = $this->findUser('claire@example.test');
        self::assertSame(UserStatus::Pending, $user->status);
        self::assertSame(Role::Customer, $user->role);
        self::assertSame('fr', $user->locale);
        self::assertNull($user->emailVerifiedAt);

        $message = $this->transport->lastMessage();
        self::assertNotNull($message);
        self::assertSame('account_confirmation', $message->template);
        self::assertSame('claire@example.test', $message->to->address);
        self::assertStringContainsString('/fr/account/confirm?token=', $message->html);
    }

    public function testRegistrationRecordsBothConsentsWithTheirLocale(): void
    {
        $result = $this->accounts->register($this->signupInput(['locale' => 'nl']), '203.0.113.11', 'nl');

        $types = array_column($this->consents->forUser($result['user_id']), 'type');
        sort($types);

        self::assertSame([AccountService::CONSENT_PRIVACY, AccountService::CONSENT_TERMS], $types);
        self::assertSame('nl', $this->consents->forUser($result['user_id'])[0]['locale']);
    }

    public function testConfirmationMailIsWrittenInTheChosenLanguage(): void
    {
        $this->accounts->register($this->signupInput(['locale' => 'de']), '203.0.113.12', 'de');

        $message = $this->transport->lastMessage();
        self::assertNotNull($message);
        self::assertSame('de', $message->locale);
        self::assertStringContainsString('/de/account/confirm?token=', $message->html);
        self::assertNotSame('', $message->subject);
    }

    public function testRegistrationRejectsInvalidInput(): void
    {
        try {
            $this->accounts->register($this->signupInput([
                'email' => 'pas-une-adresse',
                'first_name' => '',
                'phone' => 'téléphone',
                'password' => 'court',
                'accept_terms' => null,
            ]), '203.0.113.13');
            self::fail('L’inscription aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            self::assertArrayHasKey('email', $errors);
            self::assertArrayHasKey('first_name', $errors);
            self::assertArrayHasKey('phone', $errors);
            self::assertArrayHasKey('password', $errors);
            self::assertArrayHasKey('accept_terms', $errors);
        }

        self::assertNull($this->users->findByEmail('pas-une-adresse'));
        self::assertSame([], $this->transport->messages());
    }

    public function testRegistrationNeverRevealsThatAnAccountAlreadyExists(): void
    {
        $first = $this->accounts->register($this->signupInput(), '203.0.113.14');
        $this->transport->clear();

        $second = $this->accounts->register(
            $this->signupInput(['first_name' => 'Autre', 'password' => 'Autre-Mot-2026!']),
            '198.51.100.7'
        );

        // Aucun compte supplémentaire, aucun mot de passe écrasé.
        self::assertSame(0, $second['user_id']);
        self::assertTrue($second['mail_sent']);
        self::assertSame(1, $this->database->fetchValue('SELECT COUNT(*) FROM `user`'));

        $user = $this->users->findById($first['user_id']);
        self::assertNotNull($user);
        self::assertNotNull($user->passwordHash);
        self::assertTrue($this->hasher->verify(self::PASSWORD, $user->passwordHash));

        // Le titulaire réel, lui, est prévenu.
        $message = $this->transport->lastMessage();
        self::assertNotNull($message);
        self::assertSame('account_exists', $message->template);
    }

    public function testRegistrationIsRateLimitedPerAddress(): void
    {
        for ($i = 0; $i < AccountService::SIGNUP_MAX_PER_IP; $i++) {
            $this->accounts->register($this->signupInput(['email' => 'client' . $i . '@example.test']), '203.0.113.20');
        }

        $this->expectException(ValidationException::class);
        $this->accounts->register($this->signupInput(['email' => 'trop@example.test']), '203.0.113.20');
    }

    // --- Confirmation ------------------------------------------------------

    public function testConfirmationActivatesTheAccountAndBurnsTheToken(): void
    {
        $result = $this->accounts->register($this->signupInput(), '203.0.113.30');
        $token = $this->lastToken();

        $confirmation = $this->accounts->confirmEmail($token);

        self::assertTrue($confirmation['ok']);
        self::assertNotNull($confirmation['user']);
        self::assertSame(UserStatus::Active, $confirmation['user']->status);
        self::assertNotNull($confirmation['user']->emailVerifiedAt);
        self::assertSame($result['user_id'], $confirmation['user']->id);

        // Un jeton ne sert qu'une fois.
        $replay = $this->accounts->confirmEmail($token);
        self::assertFalse($replay['ok']);
        self::assertSame('account.error.token_invalid', $replay['error']);
    }

    public function testConfirmationRefusesAnUnknownOrExpiredToken(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.31');

        self::assertFalse($this->accounts->confirmEmail('jeton-inconnu')['ok']);

        $this->database->execute(
            'UPDATE `user_token` SET `expires_at` = :past',
            ['past' => gmdate('Y-m-d H:i:s', time() - 60)]
        );

        self::assertFalse($this->accounts->confirmEmail($this->lastIssuedPlainToken)['ok']);
    }

    public function testIssuingANewTokenInvalidatesThePreviousOne(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.32');
        $first = $this->lastToken();

        $userId = $this->findUser('claire@example.test')->id;
        $this->tokens->issue($userId, TokenType::EmailConfirmation);

        self::assertFalse($this->accounts->confirmEmail($first)['ok']);
    }

    // --- Réinitialisation --------------------------------------------------

    public function testPasswordResetSendsALinkAndRevokesEverySession(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.40');
        $user = $this->findUser('claire@example.test');
        $this->sessions->create(hash('sha256', 'session-a'), $user->id, 60, '203.0.113.40', 'Firefox');
        $this->sessions->create(hash('sha256', 'session-b'), $user->id, 60, '203.0.113.41', 'Safari');
        $this->transport->clear();

        $this->accounts->requestPasswordReset('  CLAIRE@Example.test ', '203.0.113.40', 'fr');

        $message = $this->transport->lastMessage();
        self::assertNotNull($message);
        self::assertSame('password_reset', $message->template);
        self::assertStringContainsString('/fr/account/reset?token=', $message->html);

        $reset = $this->accounts->resetPassword($this->lastToken(), 'Nouveau-Sel-2026!');

        self::assertTrue($reset['ok']);
        self::assertSame([], $this->sessions->activeForUser($user->id));

        $updated = $this->findUser('claire@example.test');
        self::assertNotNull($updated->passwordHash);
        self::assertTrue($this->hasher->verify('Nouveau-Sel-2026!', $updated->passwordHash));
        // Le lien de réinitialisation vaut preuve de possession de la boîte.
        self::assertSame(UserStatus::Active, $updated->status);
    }

    public function testPasswordResetStaysSilentForAnUnknownAddress(): void
    {
        $this->accounts->requestPasswordReset('inconnu@example.test', '203.0.113.42');

        self::assertSame([], $this->transport->messages());
        self::assertSame(0, $this->database->fetchValue('SELECT COUNT(*) FROM `user_token`'));
    }

    public function testPasswordResetIsRateLimitedPerAccount(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.43');
        $this->transport->clear();

        for ($i = 0; $i < AccountService::RESET_MAX_PER_ACCOUNT + 3; $i++) {
            $this->accounts->requestPasswordReset('claire@example.test', '203.0.113.43');
        }

        self::assertCount(AccountService::RESET_MAX_PER_ACCOUNT, $this->transport->messages());
    }

    public function testPasswordResetRefusesAWeakPasswordWithoutBurningTheToken(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.44');
        $this->accounts->requestPasswordReset('claire@example.test', '203.0.113.44');
        $token = $this->lastToken();

        $weak = $this->accounts->resetPassword($token, 'court');
        self::assertFalse($weak['ok']);
        self::assertNotSame('', $weak['error']);

        // Le jeton reste utilisable pour une seconde tentative valide.
        self::assertTrue($this->accounts->resetPassword($token, 'Nouveau-Sel-2026!')['ok']);
    }

    // --- Profil et mot de passe --------------------------------------------

    public function testChangingPasswordKeepsTheCurrentDeviceAndRevokesTheOthers(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.50');
        $user = $this->findUser('claire@example.test');
        $current = hash('sha256', 'session-courante');
        $this->sessions->create($current, $user->id, 60, '203.0.113.50', 'Firefox');
        $this->sessions->create(hash('sha256', 'autre'), $user->id, 60, '198.51.100.9', 'Safari');

        $result = $this->accounts->changePassword($user, self::PASSWORD, 'Nouveau-Sel-2026!', $current);

        self::assertTrue($result['ok']);
        $remaining = $this->sessions->activeForUser($user->id);
        self::assertCount(1, $remaining);

        $updated = $this->findUser('claire@example.test');
        self::assertNotNull($updated->passwordHash);
        self::assertTrue($this->hasher->verify('Nouveau-Sel-2026!', $updated->passwordHash));
    }

    public function testChangingPasswordRefusesAWrongCurrentPassword(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.51');
        $user = $this->findUser('claire@example.test');

        $result = $this->accounts->changePassword($user, 'mauvais', 'Nouveau-Sel-2026!');

        self::assertFalse($result['ok']);
        self::assertSame('account.error.current_password', $result['error']);

        $unchanged = $this->findUser('claire@example.test');
        self::assertNotNull($unchanged->passwordHash);
        self::assertTrue($this->hasher->verify(self::PASSWORD, $unchanged->passwordHash));
    }

    public function testProfileUpdateStoresThePreferredLanguage(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.52');
        $user = $this->findUser('claire@example.test');

        $this->accounts->updateProfile($user, [
            'first_name' => 'Claire-Marie',
            'last_name' => 'Dubois',
            'phone' => '+33 6 11 22 33 44',
            'locale' => 'nl',
        ]);

        $updated = $this->findUser('claire@example.test');
        self::assertSame('Claire-Marie', $updated->firstName);
        self::assertSame('nl', $updated->locale);
        self::assertSame('+33 6 11 22 33 44', $updated->phone);
    }

    public function testProfileUpdateRefusesAnEmptyNameAndAnInvalidPhone(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.53');
        $user = $this->findUser('claire@example.test');

        try {
            $this->accounts->updateProfile($user, ['first_name' => ' ', 'phone' => 'appelez-moi']);
            self::fail('La mise à jour aurait dû être refusée.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('first_name', $exception->errors());
            self::assertArrayHasKey('phone', $exception->errors());
        }

        self::assertSame('Claire', $this->findUser('claire@example.test')->firstName);
    }

    public function testProfileUpdateIgnoresAnUnknownLocale(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.54');
        $user = $this->findUser('claire@example.test');

        $this->accounts->updateProfile($user, ['locale' => 'es']);

        self::assertSame('fr', $this->findUser('claire@example.test')->locale);
    }

    // --- RGPD ---------------------------------------------------------------

    public function testExportContainsEveryPersonalFieldAndNoSessionToken(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.60');
        $user = $this->findUser('claire@example.test');
        $this->sessions->create(hash('sha256', 'jeton-secret'), $user->id, 60, '203.0.113.60', 'Firefox');

        $export = $this->accounts->exportData($this->findUser('claire@example.test'));

        self::assertSame('claire@example.test', $export['account']['email']);
        self::assertSame('Claire', $export['account']['first_name']);
        self::assertSame('+33600000000', $export['account']['phone']);
        self::assertCount(2, $export['consents']);
        self::assertCount(1, $export['active_sessions']);

        $serialised = (string) json_encode($export);
        self::assertStringNotContainsString(hash('sha256', 'jeton-secret'), $serialised);
        self::assertStringNotContainsString('password', $serialised);
    }

    public function testAnonymisationRemovesIdentifyingDataAndRevokesSessions(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.61');
        $user = $this->findUser('claire@example.test');
        $this->sessions->create(hash('sha256', 'session'), $user->id, 60, '203.0.113.61', 'Firefox');

        $this->accounts->anonymise($user);

        self::assertNull($this->users->findByEmail('claire@example.test'));
        self::assertSame([], $this->sessions->activeForUser($user->id));

        $anonymised = $this->users->findById($user->id);
        self::assertNotNull($anonymised);
        self::assertSame('', $anonymised->firstName);
        self::assertSame('', $anonymised->lastName);
        self::assertSame('', $anonymised->phone);
        self::assertNull($anonymised->passwordHash);
        self::assertSame(UserStatus::Suspended, $anonymised->status);
        self::assertStringEndsWith('@invalid.local', $anonymised->email);

        // Les consentements horodatés restent, sans donnée identifiante.
        self::assertCount(2, $this->consents->forUser($user->id));
    }

    public function testAnonymisedAccountCannotSignInAnyMore(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.62');
        $user = $this->findUser('claire@example.test');

        $this->accounts->anonymise($user);

        $anonymised = $this->users->findById($user->id);
        self::assertNotNull($anonymised);
        self::assertFalse($anonymised->status->canAuthenticate());
    }

    // --- Traçabilité --------------------------------------------------------

    public function testEveryLifecycleStepIsAudited(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.70');
        $this->accounts->confirmEmail($this->lastToken());
        $user = $this->findUser('claire@example.test');
        $this->accounts->updateProfile($user, ['locale' => 'en']);
        $this->accounts->anonymise($user);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->fetchAll('SELECT `action` FROM `audit_event` ORDER BY `id`');
        $actions = array_column($rows, 'action');

        foreach (['account.registered', 'account.confirmed', 'account.profile_updated', 'account.anonymised'] as $action) {
            self::assertContains($action, $actions);
        }
    }

    public function testOutboundMailIsRecordedWithoutItsBody(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.71');

        /** @var array<string, mixed>|null $row */
        $row = $this->database->fetchOne('SELECT * FROM `mail_message` ORDER BY `id` DESC LIMIT 1');
        self::assertNotNull($row);
        self::assertSame('outbound', $row['direction']);
        self::assertSame('sent', $row['status']);
        self::assertSame('account_confirmation', $row['template']);
        self::assertSame('claire@example.test', $row['to_address']);
        self::assertArrayNotHasKey('html', $row);
        self::assertNotSame('', (string) $row['message_id']);
    }

    public function testAFailingTransportIsRecordedAsFailedWithoutBreakingSignup(): void
    {
        $this->transport->shouldFail = true;

        $result = $this->accounts->register($this->signupInput(), '203.0.113.72');

        self::assertGreaterThan(0, $result['user_id']);
        self::assertFalse($result['mail_sent']);

        /** @var array<string, mixed>|null $row */
        $row = $this->database->fetchOne('SELECT * FROM `mail_message` ORDER BY `id` DESC LIMIT 1');
        self::assertNotNull($row);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('mail.error.rejected', (string) $row['error']);
    }

    public function testSignupWorksBeforeAnyMailSenderIsConfigured(): void
    {
        // Réglages remis à vide : c'est l'état d'une installation dont le
        // propriétaire n'a pas encore renseigné son adresse d'expédition.
        $this->database->execute('DELETE FROM `setting`');

        $result = $this->accounts->register($this->signupInput(), '203.0.113.80');

        self::assertGreaterThan(0, $result['user_id']);
        self::assertTrue($result['mail_sent']);

        $message = $this->transport->lastMessage();
        self::assertNotNull($message);
        self::assertNotFalse(filter_var($message->from->address, FILTER_VALIDATE_EMAIL));
    }

    public function testMailAddressesAreMaskedInApplicationLogs(): void
    {
        $this->accounts->register($this->signupInput(), '203.0.113.73');

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->fetchAll('SELECT `message`, `context` FROM `app_log`');
        $logs = (string) json_encode($rows, JSON_UNESCAPED_UNICODE);

        self::assertNotSame('[]', $logs);
        self::assertStringNotContainsString('claire@example.test', $logs);
        self::assertStringContainsString('c*****@example.test', $logs);
    }

    private string $lastIssuedPlainToken = '';

    /**
     * Récupère le dernier jeton en clair depuis le lien contenu dans l'e-mail :
     * la base ne stocke que son hash.
     */
    private function lastToken(): string
    {
        $message = $this->transport->lastMessage();
        self::assertNotNull($message);

        $matches = [];
        self::assertSame(
            1,
            preg_match('/token=([A-Za-z0-9_-]+)/', $message->html, $matches),
            'Aucun jeton dans l’e-mail.'
        );

        $captured = $matches[1] ?? '';
        self::assertNotSame('', $captured, 'Aucun jeton dans l’e-mail.');

        $this->lastIssuedPlainToken = rawurldecode($captured);

        return $this->lastIssuedPlainToken;
    }
}
