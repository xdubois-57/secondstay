<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SecondStay\Support\QrCode;
use SecondStay\Support\ReedSolomon;
use SecondStay\Tests\Support\QrDecoder;

/**
 * L'encodeur QR n'a d'intérêt que s'il est réellement lisible : chaque cas
 * relit donc la matrice avec un décodeur indépendant plutôt que de se
 * contenter d'en vérifier la forme.
 */
final class QrCodeTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function payloads(): array
    {
        return [
            ['A'],
            ['SecondStay'],
            ['BCD\n002\n1\nSCT\n\nRésidence\nFR7630006000011234567890189\nEUR350.00\n\n\nAcompte SS-2026-0001'],
            [str_repeat('0123456789', 12)],
            [str_repeat('X', 250)],
            [str_repeat('Ω', 100)],
            ["\x00\x01\x02\xff\xfe"],
        ];
    }

    #[DataProvider('payloads')]
    public function testRoundTripThroughAnIndependentDecoder(string $payload): void
    {
        $decoder = new QrDecoder(QrCode::encode($payload));

        self::assertSame($payload, $decoder->decode());
    }

    public function testEveryVersionUpToTheSupportedMaximumRoundTrips(): void
    {
        for ($version = 1; $version <= QrCode::MAX_VERSION; $version++) {
            $length = self::maximumLengthFor($version);
            $payload = substr(str_repeat('SecondStay-0123456789/', 100), 0, $length);

            $modules = QrCode::encode($payload);
            $decoder = new QrDecoder($modules);

            self::assertSame($version, $decoder->version(), 'Version choisie pour ' . $length . ' octets');
            self::assertSame($payload, $decoder->decode(), 'Aller-retour en version ' . $version);
        }
    }

    public function testFormatAndVersionInformationAreDuplicatedAsRequired(): void
    {
        foreach ([1, 6, 7, 14, 20] as $version) {
            $payload = substr(str_repeat('a', 700), 0, self::maximumLengthFor($version));
            $decoder = new QrDecoder(QrCode::encode($payload));

            $decoder->assertFormatCopiesAgree();
            self::assertGreaterThanOrEqual(0, $decoder->mask());
            self::assertLessThanOrEqual(7, $decoder->mask());

            if ($version >= 7) {
                self::assertSame($version, $decoder->declaredVersion());
            } else {
                self::assertNull($decoder->declaredVersion());
            }
        }
    }

    public function testFinderPatternsAreDrawnAtTheThreeCorners(): void
    {
        $modules = QrCode::encode('SecondStay');
        $size = count($modules);

        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$originX, $originY]) {
            for ($x = 0; $x < 7; $x++) {
                for ($y = 0; $y < 7; $y++) {
                    $ring = max(abs($x - 3), abs($y - 3));
                    $expected = $ring === 2 ? 0 : 1;
                    self::assertSame(
                        $expected,
                        $modules[$originY + $y][$originX + $x],
                        sprintf('Détecteur en (%d, %d), module (%d, %d)', $originX, $originY, $x, $y)
                    );
                }
            }
        }
    }

    public function testMatrixIsSquareAndBinary(): void
    {
        $modules = QrCode::encode('SecondStay');
        $size = count($modules);

        self::assertSame(21, $size);
        foreach ($modules as $row) {
            self::assertCount($size, $row);
            foreach ($row as $value) {
                self::assertContains($value, [0, 1]);
            }
        }
    }

    public function testPayloadLongerThanTheSupportedRangeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        QrCode::encode(str_repeat('a', 700));
    }

    public function testSvgRenderingCarriesTheQuietZoneAndEveryDarkModule(): void
    {
        $modules = QrCode::encode('SecondStay');
        $svg = QrCode::toSvg('SecondStay', 4, 4);

        $dark = 0;
        foreach ($modules as $row) {
            $dark += array_sum($row);
        }

        $side = (count($modules) + 8) * 4;
        self::assertStringContainsString(sprintf('viewBox="0 0 %d %d"', $side, $side), $svg);
        self::assertSame($dark, substr_count($svg, 'M'), 'Un sous-chemin par module noir');
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringEndsWith('</svg>', $svg);
    }

    public function testReedSolomonMatchesTheReferenceVectorOfTheStandard(): void
    {
        // Vecteur publié dans l'annexe I de l'ISO/IEC 18004 : message
        // « 01234567 » en mode numérique, version 1 niveau M.
        $data = [0x10, 0x20, 0x0C, 0x56, 0x61, 0x80, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11];
        $expected = [0xA5, 0x24, 0xD4, 0xC1, 0xED, 0x36, 0xC7, 0x87, 0x2C, 0x55];

        self::assertSame($expected, ReedSolomon::encode($data, 10));
    }

    public function testGaloisFieldMultiplicationIsConsistent(): void
    {
        self::assertSame(0, ReedSolomon::multiply(0, 42));
        self::assertSame(0, ReedSolomon::multiply(42, 0));
        self::assertSame(42, ReedSolomon::multiply(1, 42));

        for ($a = 1; $a < 256; $a++) {
            for ($b = 1; $b < 256; $b++) {
                self::assertSame(ReedSolomon::multiply($a, $b), ReedSolomon::multiply($b, $a));
            }
        }
    }

    private static function maximumLengthFor(int $version): int
    {
        $length = 0;
        while ($length < 666 && QrCode::versionFor($length + 1) <= $version) {
            $length++;
        }

        return $length;
    }
}
