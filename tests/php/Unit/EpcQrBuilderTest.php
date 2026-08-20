<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Payment\EpcQrBuilder;
use SecondStay\Support\QrCode;
use SecondStay\Tests\Support\QrDecoder;

final class EpcQrBuilderTest extends TestCase
{
    private const IBAN = 'FR7630006000011234567890189';

    public function testPayloadFollowsTheTwelveLineLayout(): void
    {
        $payload = EpcQrBuilder::payload('Résidence Les Mélèzes', self::IBAN, 35_000, 'SS-2026-0001 acompte');
        $lines = explode("\n", $payload);

        self::assertCount(12, $lines);
        self::assertSame('BCD', $lines[0]);
        self::assertSame('002', $lines[1]);
        self::assertSame('1', $lines[2]);
        self::assertSame('SCT', $lines[3]);
        self::assertSame('', $lines[4], 'BIC facultatif');
        self::assertSame('Résidence Les Mélèzes', $lines[5]);
        self::assertSame(self::IBAN, $lines[6]);
        self::assertSame('EUR350.00', $lines[7]);
        self::assertSame('', $lines[8], 'Code objet inutilisé');
        self::assertSame('', $lines[9], 'Référence structurée inutilisée');
        self::assertSame('SS-2026-0001 acompte', $lines[10]);
        self::assertSame('', $lines[11]);
    }

    /**
     * @return list<array{int, string}>
     */
    public static function amounts(): array
    {
        return [
            [1, 'EUR0.01'],
            [99, 'EUR0.99'],
            [100, 'EUR1.00'],
            [35_000, 'EUR350.00'],
            [1_234_567, 'EUR12345.67'],
            [99_999_999_999, 'EUR999999999.99'],
        ];
    }

    #[DataProvider('amounts')]
    public function testAmountUsesTheDecimalPointAndTwoDecimals(int $cents, string $expected): void
    {
        $payload = EpcQrBuilder::payload('Résidence', self::IBAN, $cents, 'SS-1');

        self::assertSame($expected, explode("\n", $payload)[7]);
    }

    public function testBicIsIncludedWhenSupplied(): void
    {
        $payload = EpcQrBuilder::payload('Résidence', self::IBAN, 100, 'SS-1', 'EUR', 'agri frpp');

        self::assertSame('AGRIFRPP', explode("\n", $payload)[4]);
    }

    public function testTheQrCodeReallyCarriesThePayload(): void
    {
        $payload = EpcQrBuilder::payload('Résidence Les Mélèzes', self::IBAN, 35_000, 'SS-2026-0001 acompte');
        $decoder = new QrDecoder(QrCode::encode($payload));

        self::assertSame($payload, $decoder->decode());
    }

    public function testSvgRenderingIsSelfContained(): void
    {
        $svg = EpcQrBuilder::svg('Résidence', self::IBAN, 35_000, 'SS-2026-0001');

        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $svg));
        self::assertStringNotContainsString('<script', $svg);
    }

    public function testReferenceAndNameAreKeptOnASingleLine(): void
    {
        $payload = EpcQrBuilder::payload(
            "Résidence\nLes  Mélèzes\t",
            self::IBAN,
            100,
            "SS-2026-0001\nacompte\r\nséjour"
        );
        $lines = explode("\n", $payload);

        self::assertCount(12, $lines);
        self::assertSame('Résidence Les Mélèzes', $lines[5]);
        self::assertSame('SS-2026-0001 acompte séjour', $lines[10]);
    }

    public function testLongNameAndReferenceAreTruncatedToTheSpecifiedLimits(): void
    {
        $payload = EpcQrBuilder::payload(str_repeat('a', 120), self::IBAN, 100, str_repeat('b', 200));
        $lines = explode("\n", $payload);

        self::assertSame(70, mb_strlen($lines[5], 'UTF-8'));
        self::assertSame(140, mb_strlen($lines[10], 'UTF-8'));
        self::assertLessThanOrEqual(EpcQrBuilder::MAX_LENGTH, strlen($payload));
    }

    /**
     * Un nom accentué tient dans ses 70 caractères mais pas dans les 331
     * octets du message : le virement doit malgré tout rester possible.
     */
    public function testAccentedFieldsAreShrunkToRespectTheByteLimit(): void
    {
        $payload = EpcQrBuilder::payload(str_repeat('é', 120), self::IBAN, 100, str_repeat('à', 200));
        $lines = explode("\n", $payload);

        self::assertCount(12, $lines);
        self::assertLessThanOrEqual(EpcQrBuilder::MAX_LENGTH, strlen($payload));
        self::assertNotSame('', $lines[5], 'Le bénéficiaire reste renseigné');
        self::assertNotSame('', $lines[10], 'La référence reste exploitable');
        self::assertSame(self::IBAN, $lines[6], "L'IBAN n'est jamais rogné");
        self::assertSame('EUR1.00', $lines[7], "Le montant n'est jamais rogné");
        self::assertSame($payload, (new QrDecoder(QrCode::encode($payload)))->decode());
    }

    /**
     * @return list<array{string, bool}>
     */
    public static function ibans(): array
    {
        return [
            ['FR7630006000011234567890189', true],
            ['fr76 3000 6000 0112 3456 7890 189', true],
            ['BE71096123456769', true],
            ['NL91ABNA0417164300', true],
            ['DE89370400440532013000', true],
            ['FR7630006000011234567890188', false],
            ['FR76', false],
            ['1234567890123456', false],
            ['', false],
            ['FR76300060000112345678901890000000000000', false],
        ];
    }

    #[DataProvider('ibans')]
    public function testIbanChecksumIsVerified(string $iban, bool $valid): void
    {
        self::assertSame($valid, EpcQrBuilder::isValidIban($iban));
    }

    public function testIbanIsFormattedByGroupsOfFour(): void
    {
        self::assertSame('FR76 3000 6000 0112 3456 7890 189', EpcQrBuilder::formatIban(self::IBAN));
        self::assertSame('BE71 0961 2345 6769', EpcQrBuilder::formatIban('be71 0961-2345-6769'));
    }

    /**
     * @return list<array{string, string, int, string, string, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            ['', self::IBAN, 100, 'SS-1', 'EUR', ''],
            ['Résidence', 'FR7630006000011234567890188', 100, 'SS-1', 'EUR', ''],
            ['Résidence', self::IBAN, 0, 'SS-1', 'EUR', ''],
            ['Résidence', self::IBAN, -100, 'SS-1', 'EUR', ''],
            ['Résidence', self::IBAN, 100_000_000_000, 'SS-1', 'EUR', ''],
            ['Résidence', self::IBAN, 100, 'SS-1', 'EU', ''],
            ['Résidence', self::IBAN, 100, 'SS-1', 'EUR', 'NOPE'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejected(
        string $name,
        string $iban,
        int $cents,
        string $reference,
        string $currency,
        string $bic
    ): void {
        $this->expectException(InvalidArgumentException::class);

        EpcQrBuilder::payload($name, $iban, $cents, $reference, $currency, $bic);
    }
}
