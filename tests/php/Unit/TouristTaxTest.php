<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingStatus;
use SecondStay\Booking\SubStatus;
use SecondStay\Pricing\DateRange;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsService;
use SecondStay\Tax\TouristTaxCalculator;
use SecondStay\Tests\Support\InMemorySettingsRepository;

/**
 * Taxe de séjour, volet financier.
 *
 * Le point de droit qui compte ici : les mineurs sont exonérés (article
 * L. 2333-31 du code général des collectivités territoriales). Compter les
 * enfants ferait facturer une somme indue.
 */
final class TouristTaxTest extends TestCase
{
    /**
     * @param array<string, string> $overrides
     */
    private function calculator(array $overrides = []): TouristTaxCalculator
    {
        $settings = new SettingsService(
            new SettingRegistry(),
            new InMemorySettingsRepository(),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $settings->setMany($overrides + [
            'tax.tourist_enabled' => '1',
            'tax.tourist_per_adult_night' => '1,50',
            'tax.tourist_cap_per_stay' => '0',
        ]);

        return new TouristTaxCalculator($settings);
    }

    public function testDisabledByDefaultAtInstallation(): void
    {
        $settings = new SettingsService(
            new SettingRegistry(),
            new InMemorySettingsRepository(),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );

        self::assertFalse((new TouristTaxCalculator($settings))->isEnabled());
        self::assertSame(0, (new TouristTaxCalculator($settings))->compute(2, 7));
    }

    /**
     * @return list<array{int, int, int}>
     */
    public static function stays(): array
    {
        return [
            [2, 7, 2100],
            [1, 1, 150],
            [4, 14, 8400],
            [0, 7, 0],
            [2, 0, 0],
            [-1, 7, 0],
        ];
    }

    #[DataProvider('stays')]
    public function testAmountIsPerAdultPerNight(int $adults, int $nights, int $expected): void
    {
        self::assertSame($expected, $this->calculator()->compute($adults, $nights));
    }

    public function testTheCapLimitsALongStay(): void
    {
        $calculator = $this->calculator(['tax.tourist_cap_per_stay' => '30,00']);

        self::assertSame(3000, $calculator->compute(4, 21), 'Le plafond doit s’appliquer.');
        self::assertSame(1500, $calculator->compute(2, 5), 'Un séjour court reste sous le plafond.');
    }

    public function testAZeroRateCollectsNothing(): void
    {
        self::assertSame(0, $this->calculator(['tax.tourist_per_adult_night' => '0'])->compute(4, 7));
    }

    public function testMinorsAreExempt(): void
    {
        $calculator = $this->calculator();
        $booking = $this->booking(2, 3, 1, 7);

        // Six occupants, dont deux adultes : seuls ces deux-là sont taxés.
        self::assertSame(2 * 7 * 150, $calculator->forBooking($booking));
    }

    public function testTheCalculationIsExplainable(): void
    {
        $calculator = $this->calculator(['tax.tourist_cap_per_stay' => '50,00']);
        $explanation = $calculator->explain($this->booking(2, 3, 1, 7));

        self::assertTrue($explanation['enabled']);
        self::assertSame(2, $explanation['adults']);
        self::assertSame(4, $explanation['exempt']);
        self::assertSame(7, $explanation['nights']);
        self::assertSame(150, $explanation['per_adult_night_cents']);
        self::assertSame(5000, $explanation['cap_cents']);
        self::assertSame(2100, $explanation['total_cents']);
    }

    private function booking(int $adults, int $children, int $infants, int $nights): Booking
    {
        return new Booking(
            1,
            'SS-2026-0001',
            BookingStatus::ToConfirm,
            DateRange::fromStrings(
                '2026-07-04',
                (new \DateTimeImmutable('2026-07-04'))->modify('+' . $nights . ' days')->format('Y-m-d'),
            ),
            $adults,
            $children,
            $infants,
            'fr',
            null,
            null,
            'claire@example.test',
            'Claire Dubois',
            '',
            '',
            true,
            '',
            70000,
            8000,
            0,
            78000,
            23400,
            50000,
            'EUR',
            SubStatus::NotApplicable,
            SubStatus::NotApplicable,
            SubStatus::NotApplicable,
            SubStatus::NotApplicable,
            SubStatus::NotApplicable,
            SubStatus::NotApplicable,
            null,
            '2026-01-01 00:00:00',
            null,
            null,
        );
    }
}
