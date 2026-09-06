<?php

declare(strict_types=1);

namespace SecondStay\Auth\WebAuthn;

use RuntimeException;
use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Core\Session;
use SecondStay\Security\Tokens;

/**
 * Passkeys / WebAuthn (SPECIFICATIONS.md §10).
 *
 * L'enregistrement accepte l'attestation `none` : SecondStay n'a pas besoin de
 * connaître le modèle d'authentificateur, seulement de lier une clé publique à
 * un compte. La vérification d'assertion, elle, est complète.
 */
final class WebAuthnService
{
    public const CHALLENGE_KEY = '_webauthn_challenge';
    public const CHALLENGE_USER_KEY = '_webauthn_user';
    public const CHALLENGE_TTL = 300;

    public function __construct(
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly Session $session,
        private readonly string $relyingPartyId,
        private readonly string $relyingPartyName,
        private readonly string $origin,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Les navigateurs n'acceptent une « relying party » que sur un domaine
     * enregistrable : une installation servie par adresse IP ne peut pas
     * proposer de clé d'accès. Mieux vaut ne pas afficher la fonction que
     * laisser le navigateur refuser chaque tentative.
     */
    public function isAvailable(): bool
    {
        $host = $this->relyingPartyId;

        if ($host === '' || $host === 'localhost') {
            return $host === 'localhost';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        // Un domaine enregistrable comporte au moins un point et aucun
        // caractère interdit.
        $pattern = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?'
            . '(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i';

        return preg_match($pattern, $host) === 1;
    }

    public function relyingPartyId(): string
    {
        return $this->relyingPartyId;
    }

    /**
     * Options d'enregistrement transmises au navigateur.
     *
     * @return array<string, mixed>
     */
    public function registrationOptions(User $user): array
    {
        $challenge = random_bytes(32);
        $this->storeChallenge($challenge, $user->id);

        $exclude = [];
        foreach ($this->credentials->forUser($user->id) as $credential) {
            $exclude[] = [
                'type' => 'public-key',
                'id' => (string) $credential['credential_id'],
            ];
        }

        return [
            'challenge' => self::base64UrlEncode($challenge),
            'rp' => ['id' => $this->relyingPartyId, 'name' => $this->relyingPartyName],
            'user' => [
                // L'identifiant utilisateur WebAuthn ne doit pas contenir de
                // donnée personnelle : on utilise un handle opaque stable.
                'id' => self::base64UrlEncode(hash('sha256', 'secondstay-user-' . $user->id, true)),
                'name' => $user->email,
                'displayName' => $user->displayName(),
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => CoseKey::ES256],
                ['type' => 'public-key', 'alg' => CoseKey::RS256],
            ],
            'timeout' => self::CHALLENGE_TTL * 1000,
            'attestation' => 'none',
            'excludeCredentials' => $exclude,
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
        ];
    }

    /**
     * Options d'authentification. Sans compte connu, la liste de clés reste
     * vide : les passkeys découvrables restent utilisables.
     *
     * @return array<string, mixed>
     */
    public function authenticationOptions(?User $user = null): array
    {
        $challenge = random_bytes(32);
        $this->storeChallenge($challenge, $user?->id);

        $allow = [];
        if ($user !== null) {
            foreach ($this->credentials->forUser($user->id) as $credential) {
                $allow[] = ['type' => 'public-key', 'id' => (string) $credential['credential_id']];
            }
        }

        return [
            'challenge' => self::base64UrlEncode($challenge),
            'rpId' => $this->relyingPartyId,
            'timeout' => self::CHALLENGE_TTL * 1000,
            'userVerification' => 'preferred',
            'allowCredentials' => $allow,
        ];
    }

    /**
     * Finalise un enregistrement.
     *
     * @param array<string, mixed> $response
     */
    public function completeRegistration(User $user, array $response, string $label = ''): int
    {
        $challenge = $this->takeChallenge($user->id);

        $clientDataJson = self::base64UrlDecode((string) ($response['clientDataJSON'] ?? ''));
        $this->verifyClientData($clientDataJson, 'webauthn.create', $challenge);

        $attestationObject = self::base64UrlDecode((string) ($response['attestationObject'] ?? ''));
        $decoded = Cbor::decode($attestationObject);
        if (!is_array($decoded) || !isset($decoded['authData']) || !is_string($decoded['authData'])) {
            throw new RuntimeException('webauthn.error.invalid_attestation');
        }

        $authenticatorData = AuthenticatorData::parse($decoded['authData']);

        if (!$authenticatorData->matchesRelyingParty($this->relyingPartyId)) {
            throw new RuntimeException('webauthn.error.relying_party_mismatch');
        }
        if (!$authenticatorData->userPresent()) {
            throw new RuntimeException('webauthn.error.user_not_present');
        }
        if ($authenticatorData->credentialId === null || $authenticatorData->publicKey === null) {
            throw new RuntimeException('webauthn.error.no_credential');
        }

        $credentialId = self::base64UrlEncode($authenticatorData->credentialId);
        if ($this->credentials->findByCredentialId($credentialId) !== null) {
            throw new RuntimeException('webauthn.error.already_registered');
        }

        /** @var list<string> $transports */
        $transports = [];
        foreach ((array) ($response['transports'] ?? []) as $transport) {
            if (is_string($transport) && preg_match('/^[a-z-]{1,16}$/', $transport) === 1) {
                $transports[] = $transport;
            }
        }

        $id = $this->credentials->create(
            $user->id,
            $credentialId,
            $authenticatorData->publicKey->pem,
            $authenticatorData->signCount,
            $transports,
            $label === '' ? 'Passkey' : $label,
        );

        $this->audit?->record(
            'auth.passkey_registered',
            'user',
            (string) $user->id,
            null,
            null,
            $user->id,
            $user->email,
        );

        return $id;
    }

    /**
     * Vérifie une assertion et renvoie l'identifiant de compte authentifié.
     *
     * @param array<string, mixed> $response
     *
     * @return array{user_id: int, credential_id: int}
     */
    public function verifyAssertion(array $response): array
    {
        $challenge = $this->takeChallenge(null);

        $credentialId = (string) ($response['id'] ?? '');
        if ($credentialId === '') {
            throw new RuntimeException('webauthn.error.no_credential');
        }

        $credential = $this->credentials->findByCredentialId($credentialId);
        if ($credential === null) {
            throw new RuntimeException('webauthn.error.unknown_credential');
        }

        $clientDataJson = self::base64UrlDecode((string) ($response['clientDataJSON'] ?? ''));
        $this->verifyClientData($clientDataJson, 'webauthn.get', $challenge);

        $authenticatorDataRaw = self::base64UrlDecode((string) ($response['authenticatorData'] ?? ''));
        $authenticatorData = AuthenticatorData::parse($authenticatorDataRaw);

        if (!$authenticatorData->matchesRelyingParty($this->relyingPartyId)) {
            throw new RuntimeException('webauthn.error.relying_party_mismatch');
        }
        if (!$authenticatorData->userPresent()) {
            throw new RuntimeException('webauthn.error.user_not_present');
        }

        $signature = self::base64UrlDecode((string) ($response['signature'] ?? ''));
        $signedData = $authenticatorDataRaw . hash('sha256', $clientDataJson, true);

        $publicKey = openssl_pkey_get_public((string) $credential['public_key']);
        if ($publicKey === false) {
            throw new RuntimeException('webauthn.error.invalid_key');
        }

        if (openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('webauthn.error.bad_signature');
        }

        $storedCount = (int) $credential['sign_count'];
        if ($storedCount > 0 && $authenticatorData->signCount > 0 && $authenticatorData->signCount <= $storedCount) {
            // Compteur non strictement croissant : signe possible de clonage.
            throw new RuntimeException('webauthn.error.counter_replay');
        }

        $this->credentials->updateUsage((int) $credential['id'], $authenticatorData->signCount);

        return ['user_id' => (int) $credential['user_id'], 'credential_id' => (int) $credential['id']];
    }

    public function deleteCredential(User $user, int $credentialId): bool
    {
        $deleted = $this->credentials->delete($credentialId, $user->id);
        if ($deleted) {
            $this->audit?->record(
                'auth.passkey_removed',
                'user',
                (string) $user->id,
                null,
                null,
                $user->id,
                $user->email,
            );
        }

        return $deleted;
    }

    /**
     * @return list<array{id: int, label: string, created_at: string, last_used_at: ?string, transports: string}>
     */
    public function listCredentials(User $user): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'created_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
                'transports' => (string) $row['transports'],
            ],
            $this->credentials->forUser($user->id)
        );
    }

    private function storeChallenge(string $challenge, ?int $userId): void
    {
        $this->session->set(self::CHALLENGE_KEY, [
            'value' => self::base64UrlEncode($challenge),
            'expires_at' => time() + self::CHALLENGE_TTL,
        ]);
        $this->session->set(self::CHALLENGE_USER_KEY, $userId);
    }

    private function takeChallenge(?int $expectedUserId): string
    {
        /** @var array{value?: string, expires_at?: int}|null $stored */
        $stored = $this->session->get(self::CHALLENGE_KEY);
        $storedUser = $this->session->get(self::CHALLENGE_USER_KEY);

        $this->session->remove(self::CHALLENGE_KEY);
        $this->session->remove(self::CHALLENGE_USER_KEY);

        if (!is_array($stored) || !isset($stored['value'], $stored['expires_at'])) {
            throw new RuntimeException('webauthn.error.no_challenge');
        }
        if ((int) $stored['expires_at'] < time()) {
            throw new RuntimeException('webauthn.error.challenge_expired');
        }
        if ($expectedUserId !== null && $storedUser !== null && (int) $storedUser !== $expectedUserId) {
            throw new RuntimeException('webauthn.error.challenge_mismatch');
        }

        return (string) $stored['value'];
    }

    private function verifyClientData(string $clientDataJson, string $expectedType, string $expectedChallenge): void
    {
        /** @var array{type?: string, challenge?: string, origin?: string, crossOrigin?: bool}|null $clientData */
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw new RuntimeException('webauthn.error.invalid_client_data');
        }

        if (($clientData['type'] ?? '') !== $expectedType) {
            throw new RuntimeException('webauthn.error.type_mismatch');
        }

        if (!hash_equals($expectedChallenge, (string) ($clientData['challenge'] ?? ''))) {
            throw new RuntimeException('webauthn.error.challenge_mismatch');
        }

        if (!hash_equals($this->origin, rtrim((string) ($clientData['origin'] ?? ''), '/'))) {
            throw new RuntimeException('webauthn.error.origin_mismatch');
        }

        if (($clientData['crossOrigin'] ?? false) === true) {
            throw new RuntimeException('webauthn.error.cross_origin');
        }
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('webauthn.error.invalid_encoding');
        }

        return $decoded;
    }
}
