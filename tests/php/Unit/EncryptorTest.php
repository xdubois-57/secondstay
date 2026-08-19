<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SecondStay\Security\Encryptor;

final class EncryptorTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $encryptor = Encryptor::fromSingleKey(Encryptor::generateKey());
        $cipher = $encryptor->encrypt('mot de passe SMTP');

        self::assertNotSame('mot de passe SMTP', $cipher);
        self::assertSame('mot de passe SMTP', $encryptor->decrypt($cipher));
    }

    public function testCiphertextIsNeverDeterministic(): void
    {
        $encryptor = Encryptor::fromSingleKey(Encryptor::generateKey());

        self::assertNotSame($encryptor->encrypt('même valeur'), $encryptor->encrypt('même valeur'));
    }

    public function testContextIsAuthenticated(): void
    {
        $encryptor = Encryptor::fromSingleKey(Encryptor::generateKey());
        $cipher = $encryptor->encrypt('secret', 'setting:smtp.password');

        $this->expectException(RuntimeException::class);
        $encryptor->decrypt($cipher, 'setting:imap.password');
    }

    public function testTamperingIsDetected(): void
    {
        $encryptor = Encryptor::fromSingleKey(Encryptor::generateKey());
        $cipher = $encryptor->encrypt('secret');
        [$prefix, $keyId, $nonce, $payload] = explode('.', $cipher);
        // Modification d'un octet du texte chiffré : l'authentification AEAD
        // doit la détecter.
        $payload[2] = $payload[2] === 'A' ? 'B' : 'A';

        $this->expectException(RuntimeException::class);
        $encryptor->decrypt(implode('.', [$prefix, $keyId, $nonce, $payload]));
    }

    public function testUnknownKeyIsRejected(): void
    {
        $first = Encryptor::fromSingleKey(Encryptor::generateKey());
        $second = Encryptor::fromSingleKey(Encryptor::generateKey());

        $this->expectException(\Throwable::class);
        $second->decrypt($first->encrypt('secret'));
    }

    public function testKeyRotationKeepsOldValuesReadable(): void
    {
        $oldKey = Encryptor::generateKey();
        $newKey = Encryptor::generateKey();

        $old = new Encryptor(['k1' => $oldKey], 'k1');
        $cipher = $old->encrypt('valeur historique');

        $ring = new Encryptor(['k1' => $oldKey, 'k2' => $newKey], 'k2');

        self::assertSame('valeur historique', $ring->decrypt($cipher));
        self::assertSame('k1', $ring->keyIdOf($cipher));

        $rotated = $ring->rotate($cipher);
        self::assertSame('k2', $ring->keyIdOf($rotated));
        self::assertSame('valeur historique', $ring->decrypt($rotated));
    }

    public function testInvalidKeyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        Encryptor::fromSingleKey('trop-court');
    }

    public function testEmptyKeyRingIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        new Encryptor([], 'k1');
    }

    public function testActiveKeyMustExist(): void
    {
        $this->expectException(RuntimeException::class);
        new Encryptor(['k1' => Encryptor::generateKey()], 'k9');
    }

    public function testMaskNeverRevealsTheWholeSecret(): void
    {
        self::assertSame('•••••••cret', Encryptor::mask('supersecret'));
        self::assertSame('••••', Encryptor::mask('abcd'));
        self::assertSame('', Encryptor::mask(''));
    }

    public function testIsEncryptedDetectsThePrefix(): void
    {
        $encryptor = Encryptor::fromSingleKey(Encryptor::generateKey());

        self::assertTrue($encryptor->isEncrypted($encryptor->encrypt('x')));
        self::assertFalse($encryptor->isEncrypted('texte en clair'));
    }

    public function testMalformedPayloadIsRejected(): void
    {
        $encryptor = Encryptor::fromSingleKey(Encryptor::generateKey());

        $this->expectException(RuntimeException::class);
        $encryptor->decrypt('pas.un.payload');
    }
}
