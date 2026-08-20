<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Imap\ReplyToken;

/**
 * Adresse de réponse étiquetée.
 *
 * Sans signature, connaître ou deviner une référence suffirait à faire
 * rattacher n'importe quel courrier au séjour de quelqu'un d'autre : les cas
 * ci-dessous attaquent exactement cela.
 */
final class ReplyTokenTest extends TestCase
{
    private const MAILBOX = 'logement@example.test';
    private const REFERENCE = 'WXYZ-3456';

    private function token(string $secret = 'secret-de-test'): ReplyToken
    {
        return new ReplyToken($secret);
    }

    public function testTheAddressCarriesTheReferenceAndComesBack(): void
    {
        $token = $this->token();
        $address = $token->address(self::MAILBOX, self::REFERENCE);

        self::assertStringStartsWith('logement+' . self::REFERENCE . '.', $address);
        self::assertStringEndsWith('@example.test', $address);
        self::assertNotFalse(filter_var($address, FILTER_VALIDATE_EMAIL));
        self::assertSame(self::REFERENCE, $token->referenceFrom($address));
    }

    public function testAForgedSignatureIsRefused(): void
    {
        $token = $this->token();

        self::assertNull($token->referenceFrom('logement+' . self::REFERENCE . '.0000000000000000@example.test'));
        self::assertNull($token->referenceFrom('logement+' . self::REFERENCE . '.deadbeef@example.test'));
        self::assertNull($token->referenceFrom('logement+' . self::REFERENCE . '@example.test'));
    }

    public function testASignatureFromAnotherInstallationIsRefused(): void
    {
        $address = $this->token('secret-a')->address(self::MAILBOX, self::REFERENCE);

        self::assertNull($this->token('secret-b')->referenceFrom($address));
    }

    public function testTheSignatureIsBoundToItsReference(): void
    {
        $token = $this->token();
        $address = $token->address(self::MAILBOX, self::REFERENCE);

        // La référence est remplacée, la signature conservée.
        $swapped = str_replace(self::REFERENCE, 'ABCD-2345', $address);

        self::assertNull($token->referenceFrom($swapped));
    }

    /**
     * @return list<array{string}>
     */
    public static function nonTaggedAddresses(): array
    {
        return [
            ['logement@example.test'],
            ['logement+@example.test'],
            ['logement+sans-point@example.test'],
            ['pas-une-adresse'],
            [''],
            ['+@'],
        ];
    }

    #[DataProvider('nonTaggedAddresses')]
    public function testAnAddressWithoutAValidTagYieldsNothing(string $address): void
    {
        self::assertNull($this->token()->referenceFrom($address));
    }

    public function testAnAlreadyTaggedMailboxIsNotTaggedTwice(): void
    {
        $token = $this->token();
        $once = $token->address(self::MAILBOX, self::REFERENCE);
        $twice = $token->address($once, self::REFERENCE);

        self::assertSame($once, $twice);
        self::assertSame(1, substr_count($twice, '+'));
    }

    public function testTheReferenceIsReadRegardlessOfCase(): void
    {
        $token = $this->token();
        $address = $token->address(self::MAILBOX, self::REFERENCE);

        self::assertSame(self::REFERENCE, $token->referenceFrom(strtolower($address)));
    }

    public function testTheFirstValidAddressOfAListWins(): void
    {
        $token = $this->token();
        $valid = $token->address(self::MAILBOX, self::REFERENCE);

        self::assertSame(self::REFERENCE, $token->referenceFromAny([
            'autre@example.test',
            'logement+ABCD-2345.0000000000000000@example.test',
            $valid,
        ]));

        self::assertNull($token->referenceFromAny(['autre@example.test', 'encore@example.test']));
        self::assertNull($token->referenceFromAny([]));
    }

    public function testWithoutASecretNoAddressIsTagged(): void
    {
        $token = $this->token('');

        self::assertSame(self::MAILBOX, $token->address(self::MAILBOX, self::REFERENCE));
        self::assertNull($token->referenceFrom('logement+' . self::REFERENCE . '.abcd@example.test'));
    }

    public function testAMailboxWithoutAnAtSignIsLeftAlone(): void
    {
        self::assertSame('pas-une-adresse', $this->token()->address('pas-une-adresse', self::REFERENCE));
    }

    public function testAnEmptyReferenceLeavesTheAddressUntouched(): void
    {
        self::assertSame(self::MAILBOX, $this->token()->address(self::MAILBOX, ''));
    }

    public function testTwoReferencesNeverShareASignature(): void
    {
        $token = $this->token();

        self::assertNotSame($token->tag('WXYZ-3456'), $token->tag('ABCD-2345'));
    }
}
