<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use SecondStay\Availability\AvailabilityBlockRepository;
use SecondStay\Availability\AvailabilityService;
use SecondStay\Booking\QuoteService;
use SecondStay\Booking\StayRules;
use SecondStay\I18n\Formatter;
use SecondStay\Pricing\DateRange;
use SecondStay\Pricing\PriceCalculator;
use SecondStay\Pricing\RateRepository;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Tarifs, disponibilités et règles de séjour.
 *
 * Le calcul est fait nuit par nuit : un séjour à cheval sur deux tarifs
 * additionne les tarifs réels, jamais une moyenne (SPECIFICATIONS.md §21).
 */
final class PricingTest extends DatabaseTestCase
{
    private SettingsService $settings;

    private RateRepository $rates;

    private AvailabilityBlockRepository $blocks;

    private PriceCalculator $prices;

    private StayRules $rules;

    private AvailabilityService $availability;

    private QuoteService $quotes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingsService(
            new SettingRegistry(),
            new SettingsRepository($this->database),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        $this->settings->setMany([
            'pricing.default_night_price' => '120,00',
            'pricing.cleaning_price' => '100,00',
            'pricing.cleaning_mode' => 'mandatory',
            'pricing.deposit_percent' => '30',
            'pricing.security_deposit' => '500,00',
            'booking.min_nights' => '2',
            'booking.max_guests' => '6',
            'booking.max_children' => '4',
            'booking.max_infants' => '2',
        ]);

        $this->rates = new RateRepository($this->database);
        $this->blocks = new AvailabilityBlockRepository($this->database);
        $this->prices = new PriceCalculator($this->settings, $this->rates);
        $this->rules = new StayRules($this->settings, 'Europe/Paris');
        $this->availability = new AvailabilityService(
            $this->blocks,
            $this->rates,
            $this->prices,
            $this->rules,
            'Europe/Paris',
        );
        $this->quotes = new QuoteService($this->rules, $this->availability, $this->prices);

    }

    private function range(int $offsetDays, int $nights): DateRange
    {
        $arrival = $this->availability->today()->modify('+' . $offsetDays . ' days');

        return DateRange::fromStrings(
            $arrival->format('Y-m-d'),
            $arrival->modify('+' . $nights . ' days')->format('Y-m-d')
        );
    }

    // --- Tarifs ------------------------------------------------------------

    public function testAStayWithoutOverridesUsesTheReferenceRate(): void
    {
        $quote = $this->prices->quote($this->range(90, 7));

        self::assertSame(7, $quote->nightCount());
        self::assertSame(7 * 12000, $quote->accommodationCents);
        self::assertSame(10000, $quote->cleaningCents);
        self::assertSame(7 * 12000 + 10000, $quote->totalCents);
        self::assertFalse($quote->crossesSeveralRates());
    }

    public function testAStayCrossingTwoRatesAddsTheRealNightlyPrices(): void
    {
        $range = $this->range(90, 7);
        $nights = $range->nightKeys();

        // Les trois premières nuits passent en haute saison.
        $this->rates->applyToRange(
            DateRange::fromNights($nights[0], $nights[2]),
            25000,
            null,
            'Haute saison'
        );

        $quote = $this->prices->quote($range);

        // 3 × 250 € + 4 × 120 € : jamais une moyenne.
        self::assertSame(3 * 25000 + 4 * 12000, $quote->accommodationCents);
        self::assertTrue($quote->crossesSeveralRates());

        $prices = array_column($quote->nights, 'price_cents');
        self::assertSame([25000, 25000, 25000, 12000, 12000, 12000, 12000], $prices);

        // Le prix moyen est indicatif et ne sert jamais à facturer.
        self::assertSame(
            (int) round($quote->accommodationCents / 7),
            $quote->averageNightCents()
        );
        self::assertNotSame($quote->averageNightCents() * 7, $quote->accommodationCents);
    }

    public function testAnOverrideIsMarkedAsSuch(): void
    {
        $range = $this->range(90, 3);
        $this->rates->applyToRange(DateRange::fromNights($range->arrivalKey(), $range->arrivalKey()), 30000);

        $quote = $this->prices->quote($range);

        self::assertTrue($quote->nights[0]['is_override']);
        self::assertFalse($quote->nights[1]['is_override']);
        self::assertSame(30000, $quote->nights[0]['price_cents']);
    }

    public function testClearingAnOverrideRestoresTheReferenceRate(): void
    {
        $range = $this->range(90, 4);
        $this->rates->applyToRange($range, 30000);
        self::assertSame(4 * 30000, $this->prices->quote($range)->accommodationCents);

        $this->rates->clearRange($range);

        self::assertSame(4 * 12000, $this->prices->quote($range)->accommodationCents);
    }

    public function testApplyingARateTwiceUpdatesRatherThanDuplicates(): void
    {
        $range = $this->range(90, 3);

        $this->rates->applyToRange($range, 30000);
        $this->rates->applyToRange($range, 20000, 4, 'Corrigé');

        self::assertSame(3, (int) $this->database->fetchValue('SELECT COUNT(*) FROM `rate_override`'));
        self::assertSame(3 * 20000, $this->prices->quote($range)->accommodationCents);

        $day = $this->rates->forDay($range->arrivalKey());
        self::assertNotNull($day);
        self::assertSame(4, $day['min_nights']);
        self::assertSame('Corrigé', $day['note']);
    }

    public function testOnlyTheNightsOfTheRangeArePriced(): void
    {
        $range = $this->range(90, 3);

        $this->rates->applyToRange($range, 30000);

        // La nuit du départ n'appartient pas au séjour : elle reste au tarif
        // de référence pour le séjour suivant.
        $next = DateRange::fromStrings(
            $range->departureKey(),
            $range->departure->modify('+2 days')->format('Y-m-d')
        );
        self::assertSame(2 * 12000, $this->prices->quote($next)->accommodationCents);
    }

    // --- Ménage et acompte --------------------------------------------------

    /**
     * @return list<array{string, bool|null, int}>
     */
    public static function cleaningModes(): array
    {
        return [
            ['mandatory', null, 10000],
            ['mandatory', false, 10000],
            ['optional', true, 10000],
            ['optional', false, 0],
            ['optional', null, 0],
            ['none', true, 0],
            ['none', null, 0],
        ];
    }

    #[DataProvider('cleaningModes')]
    public function testCleaningFollowsTheConfiguredMode(string $mode, ?bool $requested, int $expected): void
    {
        $this->settings->setMany(['pricing.cleaning_mode' => $mode]);

        self::assertSame($expected, $this->prices->cleaningCents($requested));
    }

    public function testMandatoryCleaningCannotBeRefused(): void
    {
        $quote = $this->prices->quote($this->range(90, 3), false);

        self::assertSame(10000, $quote->cleaningCents);
    }

    public function testTheDepositIsRoundedUpSoTheBalanceNeverExceedsTheTotal(): void
    {
        // 3 nuits à 120,01 € : le total tombe sur un centime impair.
        $range = $this->range(90, 3);
        $this->rates->applyToRange($range, 12001);

        $quote = $this->prices->quote($range);
        $total = 3 * 12001 + 10000;

        self::assertSame($total, $quote->totalCents);
        self::assertSame((int) ceil($total * 0.30), $quote->depositCents);
        self::assertGreaterThanOrEqual($total, $quote->depositCents + ($total - $quote->depositCents));
    }

    public function testADepositOfZeroOrHundredPercentIsHonoured(): void
    {
        $range = $this->range(90, 2);

        $this->settings->setMany(['pricing.deposit_percent' => '0']);
        self::assertSame(0, $this->prices->quote($range)->depositCents);

        $this->settings->setMany(['pricing.deposit_percent' => '100']);
        $quote = $this->prices->quote($range);
        self::assertSame($quote->totalCents, $quote->depositCents);
    }

    // --- Disponibilité ------------------------------------------------------

    public function testABlockMakesItsNightsUnavailable(): void
    {
        $range = $this->range(90, 7);
        $nights = $range->nightKeys();

        $this->blocks->create(DateRange::fromNights($nights[2], $nights[3]), 'owner', 'Séjour propriétaire');

        self::assertFalse($this->availability->isAvailable($range));
        self::assertSame([$nights[2], $nights[3]], $this->availability->conflictingNights($range));
    }

    public function testAStayStartingTheDayABlockEndsIsStillAvailable(): void
    {
        $range = $this->range(90, 7);

        // Le blocage se termine la veille de l'arrivée : la nuit de départ
        // d'un blocage est libre, exactement comme celle d'un séjour.
        $this->blocks->create(
            DateRange::fromNights(
                $range->arrival->modify('-3 days')->format('Y-m-d'),
                $range->arrival->modify('-1 day')->format('Y-m-d')
            ),
            'owner',
            ''
        );

        self::assertTrue($this->availability->isAvailable($range));
    }

    public function testDeletingABlockFreesItsNights(): void
    {
        $range = $this->range(90, 4);
        $id = $this->blocks->create($range, 'maintenance', 'Travaux');

        self::assertFalse($this->availability->isAvailable($range));

        self::assertTrue($this->blocks->delete($id));
        self::assertTrue($this->availability->isAvailable($range));
        self::assertFalse($this->blocks->delete($id));
    }

    public function testTheCalendarReportsEveryNightState(): void
    {
        $range = $this->range(90, 5);
        $nights = $range->nightKeys();
        $this->blocks->create(DateRange::fromNights($nights[1], $nights[1]), 'owner', 'Interne');
        $this->rates->applyToRange(DateRange::fromNights($nights[3], $nights[3]), 30000);

        $states = $this->availability->nightStates($range);

        self::assertSame(AvailabilityService::STATE_FREE, $states[$nights[0]]['state']);
        self::assertSame(AvailabilityService::STATE_BLOCKED, $states[$nights[1]]['state']);
        // Le motif interne n'est jamais exposé, seulement le type.
        self::assertSame('owner', $states[$nights[1]]['label']);
        self::assertStringNotContainsString('Interne', json_encode($states) ?: '');
        self::assertSame(30000, $states[$nights[3]]['price_cents']);
        self::assertTrue($states[$nights[3]]['is_override']);
    }

    public function testPastNightsAreMarkedAsSuch(): void
    {
        $yesterday = $this->availability->today()->modify('-3 days');
        $states = $this->availability->nightStates(DateRange::fromStrings(
            $yesterday->format('Y-m-d'),
            $this->availability->today()->format('Y-m-d')
        ));

        foreach ($states as $state) {
            self::assertSame(AvailabilityService::STATE_PAST, $state['state']);
        }
    }

    public function testTheMonthGridStartsOnMondayAndEndsOnSunday(): void
    {
        $month = $this->availability->month('2026-07');

        self::assertSame('2026-07', $month['month']);
        self::assertSame('2026-06', $month['previous']);
        self::assertSame('2026-08', $month['next']);

        $first = $month['weeks'][0][0];
        $lastWeek = $month['weeks'][count($month['weeks']) - 1];
        $last = $lastWeek[count($lastWeek) - 1];

        self::assertSame('1', date('N', (int) strtotime($first['day'])));
        self::assertSame('7', date('N', (int) strtotime($last['day'])));

        foreach ($month['weeks'] as $week) {
            self::assertCount(7, $week);
        }

        // Les jours des mois voisins sont présents mais signalés.
        $inMonth = array_filter(array_merge(...$month['weeks']), static fn (array $day): bool => $day['in_month']);
        self::assertCount(31, $inMonth);
    }

    /**
     * @return list<array{?string}>
     */
    public static function aberrantMonths(): array
    {
        return [[null], [''], ['2026-13'], ['abcd-ef'], ['0000-00'], ['2026-07-12'], ['1900-01']];
    }

    #[DataProvider('aberrantMonths')]
    public function testAnAberrantMonthIsBroughtBackIntoRange(?string $month): void
    {
        $normalised = $this->availability->normaliseMonth($month);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $normalised);

        $floor = $this->availability->today()->modify('-1 month')->format('Y-m');
        self::assertGreaterThanOrEqual($floor, $normalised);
    }

    // --- Règles de séjour ---------------------------------------------------

    public function testAStayShorterThanTheMinimumIsRefused(): void
    {
        self::assertSame(['booking.error.min_nights'], $this->rules->validateRange($this->range(90, 1)));
        self::assertSame([], $this->rules->validateRange($this->range(90, 2)));
    }

    public function testSaturdayToSaturdayForcesBothEnds(): void
    {
        $this->settings->setMany(['booking.saturday_to_saturday' => '1']);

        $saturday = $this->availability->today()->modify('next saturday')->modify('+30 days');
        $saturday = $saturday->modify('+' . ((6 - (int) $saturday->format('N') + 7) % 7) . ' days');

        $week = DateRange::fromStrings(
            $saturday->format('Y-m-d'),
            $saturday->modify('+7 days')->format('Y-m-d')
        );
        self::assertSame([], $this->rules->validateRange($week));

        $wrongStart = DateRange::fromStrings(
            $saturday->modify('+1 day')->format('Y-m-d'),
            $saturday->modify('+8 days')->format('Y-m-d')
        );
        self::assertContains('booking.error.arrival_weekday', $this->rules->validateRange($wrongStart));

        $wrongLength = DateRange::fromStrings(
            $saturday->format('Y-m-d'),
            $saturday->modify('+5 days')->format('Y-m-d')
        );
        self::assertContains('booking.error.departure_weekday', $this->rules->validateRange($wrongLength));
    }

    public function testNightMultipleIsEnforced(): void
    {
        $this->settings->setMany(['booking.night_multiple' => '7']);

        self::assertSame([], $this->rules->validateRange($this->range(90, 14)));
        self::assertContains('booking.error.night_multiple', $this->rules->validateRange($this->range(90, 10)));
    }

    public function testAStayTooCloseOrTooFarIsRefused(): void
    {
        $this->settings->setMany(['booking.advance_days' => '7', 'booking.horizon_days' => '60']);

        self::assertContains('booking.error.too_early', $this->rules->validateRange($this->range(2, 3)));
        self::assertContains('booking.error.too_far', $this->rules->validateRange($this->range(200, 3)));
        self::assertSame([], $this->rules->validateRange($this->range(20, 3)));
    }

    /**
     * @return list<array{int, int, int, list<string>}>
     */
    public static function guestCombinations(): array
    {
        return [
            [2, 0, 0, []],
            [6, 0, 0, []],
            // Les bébés ne comptent pas dans la capacité de couchage.
            [6, 0, 2, []],
            [0, 2, 0, ['booking.error.min_adults']],
            [4, 5, 0, ['booking.error.max_children', 'booking.error.max_guests']],
            [7, 0, 0, ['booking.error.max_guests']],
            [2, 0, 3, ['booking.error.max_infants']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('guestCombinations')]
    public function testGuestRulesAreEnforced(int $adults, int $children, int $infants, array $expected): void
    {
        self::assertSame($expected, $this->rules->validateGuests($adults, $children, $infants));
    }

    // --- Devis complet ------------------------------------------------------

    public function testACompleteQuoteCombinesRulesAvailabilityAndPrice(): void
    {
        $range = $this->range(90, 7);
        $nights = $range->nightKeys();
        $this->rates->applyToRange(DateRange::fromNights($nights[0], $nights[2]), 25000);

        $result = $this->quotes->evaluate([
            'arrival' => $range->arrivalKey(),
            'departure' => $range->departureKey(),
            'adults' => 4,
            'children' => 2,
        ]);

        self::assertTrue($result['ok']);
        self::assertSame([], $result['errors']);
        self::assertNotNull($result['quote']);
        self::assertSame(3 * 25000 + 4 * 12000, $result['quote']['accommodation_cents']);
        self::assertSame(3 * 25000 + 4 * 12000 + 10000, $result['quote']['total_cents']);
        self::assertSame(6, $result['rules']['max_guests']);
    }

    public function testAnUnavailableStayIsRefusedButStillPriced(): void
    {
        $range = $this->range(90, 5);
        $this->blocks->create(DateRange::fromNights($range->nightKeys()[1], $range->nightKeys()[1]), 'owner', '');

        $result = $this->quotes->evaluate([
            'arrival' => $range->arrivalKey(),
            'departure' => $range->departureKey(),
            'adults' => 2,
        ]);

        self::assertFalse($result['ok']);
        self::assertContains('booking.error.unavailable', $result['errors']);
        self::assertSame([$range->nightKeys()[1]], $result['conflicts']);
        // Le visiteur voit tout de même ce que coûterait ce séjour.
        self::assertNotNull($result['quote']);
    }

    public function testAMalformedDateIsReportedWithoutCrashing(): void
    {
        $result = $this->quotes->evaluate(['arrival' => 'hier', 'departure' => 'demain', 'adults' => 2]);

        self::assertFalse($result['ok']);
        self::assertSame(['booking.error.invalid_date'], $result['errors']);
        self::assertNull($result['quote']);
    }

    public function testErrorsAreReportedOnlyOnce(): void
    {
        $this->settings->setMany(['booking.min_nights' => '7']);

        $result = $this->quotes->evaluate([
            'arrival' => $this->range(90, 1)->arrivalKey(),
            'departure' => $this->range(90, 1)->departureKey(),
            'adults' => 0,
        ]);

        self::assertSame($result['errors'], array_values(array_unique($result['errors'])));
    }

    // --- Formatage localisé --------------------------------------------------

    public function testTheSameTotalIsFormattedPerLocaleButComputedOnce(): void
    {
        $range = $this->range(90, 7);
        $nights = $range->nightKeys();
        $this->rates->applyToRange(DateRange::fromNights($nights[0], $nights[2]), 25000);

        $quote = $this->prices->quote($range);
        $expected = 3 * 25000 + 4 * 12000 + 10000;

        self::assertSame($expected, $quote->totalCents);

        $rendered = [];
        foreach (['fr', 'en', 'nl', 'de'] as $locale) {
            $formatted = (new Formatter($locale, 'Europe/Paris', 'EUR'))->money($quote->totalCents);

            // Chaque locale affiche exactement le même montant, avec ses
            // propres séparateurs : les chiffres, eux, ne changent pas.
            self::assertSame('133000', preg_replace('/\D+/u', '', $formatted), $locale);
            $rendered[] = $formatted;
        }

        // Les quatre rendus ne sont pas tous identiques : le formatage suit
        // réellement la locale.
        self::assertGreaterThan(1, count(array_unique($rendered)));
    }
}
