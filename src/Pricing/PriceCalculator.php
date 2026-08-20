<?php

declare(strict_types=1);

namespace SecondStay\Pricing;

use SecondStay\Settings\SettingsService;

/**
 * Calcul nuit par nuit (SPECIFICATIONS.md §21).
 *
 * Chaque nuit porte son propre tarif : un séjour à cheval sur deux saisons
 * additionne les tarifs réels, jamais une moyenne. Les montants sont des
 * entiers de centimes — aucun flottant n'intervient dans un montant facturé.
 */
final class PriceCalculator
{
    public const CLEANING_NONE = 'none';
    public const CLEANING_OPTIONAL = 'optional';
    public const CLEANING_MANDATORY = 'mandatory';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly RateRepository $rates,
        private readonly string $currency = 'EUR',
    ) {
    }

    /**
     * @param bool|null $withCleaning null = suivre le mode configuré
     */
    public function quote(DateRange $range, ?bool $withCleaning = null): Quote
    {
        $defaultPrice = $this->settings->money('pricing.default_night_price');
        $overrides = $range->nights() > 0
            ? $this->rates->forRange($range->arrivalKey(), $range->lastNightKey())
            : [];

        $nights = [];
        $accommodation = 0;

        foreach ($range->nightKeys() as $day) {
            $override = $overrides[$day] ?? null;
            $price = $override === null ? $defaultPrice : $override['price_cents'];

            $nights[] = ['day' => $day, 'price_cents' => $price, 'is_override' => $override !== null];
            $accommodation += $price;
        }

        $cleaning = $this->cleaningCents($withCleaning);
        $total = $accommodation + $cleaning;

        return new Quote(
            $range,
            $nights,
            $accommodation,
            $cleaning,
            $total,
            $this->depositCents($total),
            $this->settings->money('pricing.security_deposit'),
            $this->currency,
        );
    }

    /**
     * Le ménage est obligatoire par défaut : le refuser n'est possible que si
     * le mode le permet (SPECIFICATIONS.md §24).
     */
    public function cleaningCents(?bool $requested = null): int
    {
        return match ($this->cleaningMode()) {
            self::CLEANING_NONE => 0,
            self::CLEANING_OPTIONAL => $requested === true ? $this->settings->money('pricing.cleaning_price') : 0,
            default => $this->settings->money('pricing.cleaning_price'),
        };
    }

    public function cleaningMode(): string
    {
        $mode = $this->settings->string('pricing.cleaning_mode');

        return $mode !== '' ? $mode : self::CLEANING_MANDATORY;
    }

    /**
     * Acompte demandé à la réservation. L'arrondi se fait au centime
     * supérieur : le solde restant ne peut jamais dépasser le total.
     */
    public function depositCents(int $totalCents): int
    {
        $percent = $this->settings->int('pricing.deposit_percent');
        if ($percent <= 0) {
            return 0;
        }
        if ($percent >= 100) {
            return $totalCents;
        }

        return (int) ceil($totalCents * $percent / 100);
    }

    /**
     * Tarif affiché pour une nuit isolée, utilisé par le calendrier.
     *
     * @param array<string, array{price_cents: int, min_nights: ?int, note: string}> $overrides
     */
    public function nightPrice(string $day, array $overrides): int
    {
        return $overrides[$day]['price_cents'] ?? $this->settings->money('pricing.default_night_price');
    }
}
