<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Auth\WebAuthn\WebAuthnCredentialRepository;
use SecondStay\Auth\WebAuthn\WebAuthnService;
use SecondStay\Core\Session;
use SecondStay\Tests\Support\DatabaseTestCase;
use SecondStay\Tests\Support\FakeAuthenticator;

/**
 * Passkeys : l'implémentation est vérifiée contre un authentificateur simulé
 * produisant de vraies structures CBOR et de vraies signatures ECDSA.
 */
final class WebAuthnServiceTest extends DatabaseTestCase
{
    private const RP_ID = 'localhost';
    private const ORIGIN = 'http://localhost:8123';

    private WebAuthnService $webauthn;

    private WebAuthnCredentialRepository $credentials;

    private Session $session;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->database);
        $id = $users->create(
            'client@example.test',
            self::passwordHash('Marée-Haute-2026!'),
            'Claire',
            'Dubois',
            '',
            Role::Customer,
            'fr',
            UserStatus::Active,
        );

        $user = $users->findById($id);
        self::assertNotNull($user);
        $this->user = $user;

        $this->session = new Session();
        $this->session->start();
        $this->credentials = new WebAuthnCredentialRepository($this->database);

        $this->webauthn = new WebAuthnService(
            $this->credentials,
            $this->session,
            self::RP_ID,
            'SecondStay',
            self::ORIGIN,
            new AuditTrail($this->database),
        );
    }

    private function challenge(): string
    {
        /** @var array{value: string} $stored */
        $stored = $this->session->get(WebAuthnService::CHALLENGE_KEY);

        return $stored['value'];
    }

    /**
     * @return list<array{string, bool}>
     */
    public static function relyingPartyHosts(): array
    {
        return [
            ['localhost', true],
            ['sejour.example.test', true],
            ['maison-des-pins.fr', true],
            ['', false],
            // Une adresse IP n'est pas un domaine enregistrable : aucun
            // navigateur n'accepterait la clé.
            ['127.0.0.1', false],
            ['192.168.1.10', false],
            ['::1', false],
            // Un nom sans point n'est pas non plus utilisable.
            ['intranet', false],
        ];
    }

    #[DataProvider('relyingPartyHosts')]
    public function testPasskeysAreOnlyOfferedOnARegistrableDomain(string $host, bool $expected): void
    {
        $service = new WebAuthnService(
            $this->credentials,
            $this->session,
            $host,
            'SecondStay',
            'http://' . ($host === '' ? 'localhost' : $host),
        );

        self::assertSame($expected, $service->isAvailable());
        self::assertSame($host, $service->relyingPartyId());
    }

    public function testRegistrationOptionsAreComplete(): void
    {
        $options = $this->webauthn->registrationOptions($this->user);

        self::assertSame(self::RP_ID, $options['rp']['id']);
        self::assertSame('client@example.test', $options['user']['name']);
        self::assertSame('none', $options['attestation']);
        self::assertNotSame('', $options['challenge']);

        $algorithms = array_column($options['pubKeyCredParams'], 'alg');
        self::assertContains(-7, $algorithms);
        self::assertContains(-257, $algorithms);

        // L'identifiant WebAuthn ne doit contenir aucune donnée personnelle.
        $handle = WebAuthnService::base64UrlDecode($options['user']['id']);
        self::assertStringNotContainsString('client@example.test', $handle);
    }

    public function testFullRegistrationAndAuthentication(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);

        $this->webauthn->registrationOptions($this->user);
        $registration = $authenticator->register($this->challenge());
        $credentialId = $this->webauthn->completeRegistration($this->user, $registration, 'iPhone de Claire');

        self::assertGreaterThan(0, $credentialId);
        self::assertSame(1, $this->credentials->countForUser($this->user->id));

        $this->webauthn->authenticationOptions($this->user);
        $assertion = $authenticator->assert($this->challenge());
        $result = $this->webauthn->verifyAssertion($assertion);

        self::assertSame($this->user->id, $result['user_id']);
        self::assertSame($credentialId, $result['credential_id']);
    }

    public function testCredentialIsListedWithItsLabel(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);
        $this->webauthn->registrationOptions($this->user);
        $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge()), 'Clé USB');

        $listed = $this->webauthn->listCredentials($this->user);

        self::assertCount(1, $listed);
        self::assertSame('Clé USB', $listed[0]['label']);
        self::assertStringContainsString('internal', $listed[0]['transports']);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);
        $this->webauthn->registrationOptions($this->user);
        $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge()));

        $this->webauthn->authenticationOptions($this->user);
        $assertion = $authenticator->assert($this->challenge());
        $signature = WebAuthnService::base64UrlDecode($assertion['signature']);
        $signature[10] = $signature[10] === "\x01" ? "\x02" : "\x01";
        $assertion['signature'] = WebAuthnService::base64UrlEncode($signature);

        $this->expectExceptionMessage('webauthn.error.bad_signature');
        $this->webauthn->verifyAssertion($assertion);
    }

    public function testChallengeReplayIsRejected(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);
        $this->webauthn->registrationOptions($this->user);
        $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge()));

        $this->webauthn->authenticationOptions($this->user);
        $assertion = $authenticator->assert($this->challenge());
        $this->webauthn->verifyAssertion($assertion);

        // Le défi a été consommé : rejouer la même assertion échoue.
        $this->expectExceptionMessage('webauthn.error.no_challenge');
        $this->webauthn->verifyAssertion($assertion);
    }

    public function testWrongOriginIsRejected(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, 'https://evil.test');
        $this->webauthn->registrationOptions($this->user);

        $this->expectExceptionMessage('webauthn.error.origin_mismatch');
        $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge(), 'https://evil.test'));
    }

    public function testWrongRelyingPartyIsRejected(): void
    {
        $authenticator = new FakeAuthenticator('evil.test', self::ORIGIN);
        $this->webauthn->registrationOptions($this->user);

        $this->expectExceptionMessage('webauthn.error.relying_party_mismatch');
        $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge()));
    }

    public function testWrongChallengeIsRejected(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);
        $this->webauthn->registrationOptions($this->user);

        $this->expectExceptionMessage('webauthn.error.challenge_mismatch');
        $this->webauthn->completeRegistration(
            $this->user,
            $authenticator->register(WebAuthnService::base64UrlEncode(random_bytes(32)))
        );
    }

    public function testMissingChallengeIsRejected(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);

        $this->expectExceptionMessage('webauthn.error.no_challenge');
        $this->webauthn->completeRegistration($this->user, $authenticator->register('inexistant'));
    }

    public function testClonedAuthenticatorIsDetectedByTheSignatureCounter(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);
        $this->webauthn->registrationOptions($this->user);
        $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge()));

        $this->webauthn->authenticationOptions($this->user);
        $this->webauthn->verifyAssertion($authenticator->assert($this->challenge(), null, 50));

        $this->webauthn->authenticationOptions($this->user);

        $this->expectExceptionMessage('webauthn.error.counter_replay');
        $this->webauthn->verifyAssertion($authenticator->assert($this->challenge(), null, 40));
    }

    public function testUnknownCredentialIsRejected(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);
        $this->webauthn->authenticationOptions($this->user);

        $this->expectExceptionMessage('webauthn.error.unknown_credential');
        $this->webauthn->verifyAssertion($authenticator->assert($this->challenge()));
    }

    public function testSameCredentialCannotBeRegisteredTwice(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);

        $this->webauthn->registrationOptions($this->user);
        $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge()));

        $this->webauthn->registrationOptions($this->user);

        $this->expectExceptionMessage('webauthn.error.already_registered');
        $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge()));
    }

    public function testCredentialCanBeRemovedByItsOwnerOnly(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);
        $this->webauthn->registrationOptions($this->user);
        $id = $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge()));

        $users = new UserRepository($this->database);
        $otherId = $users->create('autre@example.test', null, 'Autre', 'Compte', '', Role::Customer, 'fr', UserStatus::Active);
        $other = $users->findById($otherId);
        self::assertNotNull($other);

        self::assertFalse($this->webauthn->deleteCredential($other, $id));
        self::assertSame(1, $this->credentials->countForUser($this->user->id));

        self::assertTrue($this->webauthn->deleteCredential($this->user, $id));
        self::assertSame(0, $this->credentials->countForUser($this->user->id));
    }

    public function testRegistrationIsAudited(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);
        $this->webauthn->registrationOptions($this->user);
        $this->webauthn->completeRegistration($this->user, $authenticator->register($this->challenge()));

        $actions = array_column((new AuditTrail($this->database))->recent(), 'action');
        self::assertContains('auth.passkey_registered', $actions);
    }

    public function testExpiredChallengeIsRejected(): void
    {
        $authenticator = new FakeAuthenticator(self::RP_ID, self::ORIGIN);
        $this->webauthn->registrationOptions($this->user);
        $challenge = $this->challenge();

        $this->session->set(WebAuthnService::CHALLENGE_KEY, [
            'value' => $challenge,
            'expires_at' => time() - 1,
        ]);

        $this->expectExceptionMessage('webauthn.error.challenge_expired');
        $this->webauthn->completeRegistration($this->user, $authenticator->register($challenge));
    }

    public function testInvalidClientDataIsRejected(): void
    {
        $this->webauthn->registrationOptions($this->user);

        $this->expectException(RuntimeException::class);
        $this->webauthn->completeRegistration($this->user, [
            'clientDataJSON' => WebAuthnService::base64UrlEncode('pas du json'),
            'attestationObject' => WebAuthnService::base64UrlEncode('x'),
        ]);
    }
}
