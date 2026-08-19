<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use RuntimeException;
use SecondStay\Auth\WebAuthn\AuthenticatorData;
use SecondStay\Auth\WebAuthn\CoseKey;
use SecondStay\Auth\WebAuthn\WebAuthnService;

/**
 * Authentificateur WebAuthn simulé.
 *
 * Il produit de véritables structures CBOR et de véritables signatures ECDSA :
 * la vérification côté serveur est donc testée pour de bon, sans matériel.
 */
final class FakeAuthenticator
{
    private \OpenSSLAsymmetricKey $privateKey;

    private string $credentialId;

    private int $signCount = 0;

    public function __construct(
        private readonly string $relyingPartyId = 'localhost',
        private readonly string $origin = 'http://localhost:8123',
    ) {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false) {
            throw new RuntimeException('Impossible de générer une clé EC de test.');
        }

        $this->privateKey = $key;
        $this->credentialId = random_bytes(32);
    }

    public function credentialIdBase64(): string
    {
        return WebAuthnService::base64UrlEncode($this->credentialId);
    }

    /**
     * @return array{clientDataJSON: string, attestationObject: string, transports: list<string>}
     */
    public function register(string $challenge, ?string $origin = null): array
    {
        $clientData = [
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => $origin ?? $this->origin,
            'crossOrigin' => false,
        ];

        $authData = $this->authenticatorData(true);

        $attestation = CborEncoder::encode([
            'fmt' => 'none',
            'attStmt' => [],
            'authData' => new CborByteString($authData),
        ]);

        return [
            'clientDataJSON' => WebAuthnService::base64UrlEncode((string) json_encode($clientData)),
            'attestationObject' => WebAuthnService::base64UrlEncode($attestation),
            'transports' => ['internal', 'hybrid'],
        ];
    }

    /**
     * @return array{id: string, clientDataJSON: string, authenticatorData: string, signature: string}
     */
    public function assert(string $challenge, ?string $origin = null, ?int $forcedSignCount = null): array
    {
        $clientData = [
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => $origin ?? $this->origin,
            'crossOrigin' => false,
        ];

        $clientDataJson = (string) json_encode($clientData);
        $authData = $this->authenticatorData(false, $forcedSignCount);

        $signature = '';
        if (!openssl_sign($authData . hash('sha256', $clientDataJson, true), $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature de test impossible.');
        }

        return [
            'id' => $this->credentialIdBase64(),
            'clientDataJSON' => WebAuthnService::base64UrlEncode($clientDataJson),
            'authenticatorData' => WebAuthnService::base64UrlEncode($authData),
            'signature' => WebAuthnService::base64UrlEncode($signature),
        ];
    }

    private function authenticatorData(bool $withCredential, ?int $forcedSignCount = null): string
    {
        $this->signCount = $forcedSignCount ?? ($this->signCount + 1);

        $flags = AuthenticatorData::FLAG_USER_PRESENT | AuthenticatorData::FLAG_USER_VERIFIED;
        if ($withCredential) {
            $flags |= AuthenticatorData::FLAG_ATTESTED_CREDENTIAL;
        }

        $data = hash('sha256', $this->relyingPartyId, true)
            . chr($flags)
            . pack('N', $this->signCount);

        if ($withCredential) {
            $data .= str_repeat("\x00", 16)                       // AAGUID
                . pack('n', strlen($this->credentialId))
                . $this->credentialId
                . $this->coseKey();
        }

        return $data;
    }

    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->privateKey);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Clé EC de test illisible.');
        }

        return CborEncoder::encode([
            1 => 2,                     // kty : EC2
            3 => CoseKey::ES256,        // alg
            -1 => 1,                    // crv : P-256
            -2 => new CborByteString(str_pad((string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT)),
            -3 => new CborByteString(str_pad((string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT)),
        ]);
    }
}
