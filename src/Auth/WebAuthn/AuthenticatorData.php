<?php

declare(strict_types=1);

namespace SecondStay\Auth\WebAuthn;

use RuntimeException;

/**
 * Données d'authentificateur WebAuthn (§6.1).
 */
final class AuthenticatorData
{
    public const FLAG_USER_PRESENT = 0x01;
    public const FLAG_USER_VERIFIED = 0x04;
    public const FLAG_ATTESTED_CREDENTIAL = 0x40;
    public const FLAG_EXTENSION_DATA = 0x80;

    private function __construct(
        public readonly string $rpIdHash,
        public readonly int $flags,
        public readonly int $signCount,
        public readonly ?string $credentialId,
        public readonly ?CoseKey $publicKey,
    ) {
    }

    public static function parse(string $data): self
    {
        if (strlen($data) < 37) {
            throw new RuntimeException('webauthn.error.invalid_authenticator_data');
        }

        $rpIdHash = substr($data, 0, 32);
        $flags = ord($data[32]);
        $signCountBytes = substr($data, 33, 4);
        $unpacked = unpack('N', $signCountBytes);
        $signCount = $unpacked === false ? 0 : (int) $unpacked[1];

        $credentialId = null;
        $publicKey = null;

        if (($flags & self::FLAG_ATTESTED_CREDENTIAL) !== 0) {
            if (strlen($data) < 55) {
                throw new RuntimeException('webauthn.error.invalid_authenticator_data');
            }

            $lengthBytes = unpack('n', substr($data, 53, 2));
            $credentialIdLength = $lengthBytes === false ? 0 : (int) $lengthBytes[1];

            if ($credentialIdLength <= 0 || strlen($data) < 55 + $credentialIdLength) {
                throw new RuntimeException('webauthn.error.invalid_authenticator_data');
            }

            $credentialId = substr($data, 55, $credentialIdLength);

            $coseRaw = substr($data, 55 + $credentialIdLength);
            $decoded = Cbor::decodeAt($coseRaw, 0)['value'];
            if (!is_array($decoded)) {
                throw new RuntimeException('webauthn.error.invalid_key');
            }

            /** @var array<int, mixed> $decoded */
            $publicKey = CoseKey::fromCoseArray($decoded);
        }

        return new self($rpIdHash, $flags, $signCount, $credentialId, $publicKey);
    }

    public function userPresent(): bool
    {
        return ($this->flags & self::FLAG_USER_PRESENT) !== 0;
    }

    public function userVerified(): bool
    {
        return ($this->flags & self::FLAG_USER_VERIFIED) !== 0;
    }

    public function matchesRelyingParty(string $rpId): bool
    {
        return hash_equals(hash('sha256', $rpId, true), $this->rpIdHash);
    }
}
