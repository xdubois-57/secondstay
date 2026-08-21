<?php

declare(strict_types=1);

namespace SecondStay\Tax;

use SecondStay\Booking\Booking;
use SecondStay\Settings\SettingsService;

/**
 * Moteur de taxe de séjour versionné (SPECIFICATIONS.md §29 et §63).
 *
 * Trois règles gouvernent ce calcul :
 *
 * 1. **le passé ne se recalcule pas**. Si un contexte a été figé avec le
 *    séjour, c'est lui qui fait foi : un barème voté depuis ne peut pas
 *    changer le montant d'une réservation déjà engagée ;
 * 2. **le barème est daté**. Une règle versionnée s'applique selon la date
 *    d'arrivée et le classement du logement ; à défaut, la configuration
 *    tient lieu de barème courant, ce qui permet à une petite installation de
 *    fonctionner sans jamais saisir de règle ;
 * 3. **les mineurs sont exonérés** (article L. 2333-31 du code général des
 *    collectivités territoriales). Compter les enfants ferait facturer une
 *    somme indue.
 */
final class TouristTaxCalculator
{
    /** Classement retenu quand le logement n'en a pas. */
    public const DEFAULT_CLASSIFICATION = 'unclassified';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ?TouristTaxRuleRepository $rules = null,
        private readonly ?TouristTaxContextRepository $contexts = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool('tax.tourist_enabled');
    }

    /**
     * Montant dû pour un séjour, en centimes.
     */
    public function forBooking(Booking $booking): int
    {
        $frozen = $this->contexts?->find($booking->id);
        if ($frozen !== null) {
            return $frozen['amount_cents'];
        }

        return $this->explain($booking)['total_cents'];
    }

    /**
     * Calcul brut, sans séjour : utilisé par les devis et les tests.
     */
    public function compute(int $adults, int $nights, ?string $day = null): int
    {
        if (!$this->isEnabled() || $adults <= 0 || $nights <= 0) {
            return 0;
        }

        $rule = $this->ruleFor($day ?? gmdate('Y-m-d'));

        return $rule->compute($adults, $nights);
    }

    /**
     * Détail du calcul : ce qui permet de l'expliquer, plus tard, à quelqu'un
     * qui conteste.
     *
     * @return array<string, mixed>
     */
    public function explain(Booking $booking): array
    {
        $frozen = $this->contexts?->find($booking->id);
        if ($frozen !== null) {
            return $frozen['context'] + [
                'total_cents' => $frozen['amount_cents'],
                'frozen_at' => $frozen['computed_at'],
            ];
        }

        return $this->contextFor($booking);
    }

    /**
     * Fige le contexte de calcul avec le séjour.
     *
     * Appelé une fois, à la création : le montant devient alors une donnée du
     * séjour, au même titre que le prix des nuits.
     */
    public function freeze(Booking $booking): void
    {
        if ($this->contexts === null) {
            return;
        }

        $context = $this->contextFor($booking);
        $ruleId = is_int($context['rule_id'] ?? null) ? $context['rule_id'] : null;

        $this->contexts->freeze($booking->id, $ruleId, (int) $context['total_cents'], $context);
    }

    /**
     * Classement du logement, tel qu'il est configuré.
     */
    public function classification(): string
    {
        $classification = trim($this->settings->string('tax.classification'));

        return $classification === '' ? self::DEFAULT_CLASSIFICATION : $classification;
    }

    // --- Interne ------------------------------------------------------------------

    /**
     * Contexte complet du calcul, recalculé depuis les règles en vigueur.
     *
     * @return array<string, mixed>
     */
    private function contextFor(Booking $booking): array
    {
        $day = $booking->range->arrival->format('Y-m-d');
        $rule = $this->ruleFor($day);
        $taxable = max(0, $booking->adults);
        $nights = $booking->nights();

        $total = $this->isEnabled() ? $rule->compute($taxable, $nights) : 0;

        return [
            'enabled' => $this->isEnabled(),
            'rule_id' => $rule->id > 0 ? $rule->id : null,
            'territory' => $rule->territory,
            'classification' => $rule->classification,
            'effective_from' => $rule->effectiveFrom,
            'effective_to' => $rule->effectiveTo,
            'source_url' => $rule->sourceUrl,
            'arrival' => $day,
            'adults' => $taxable,
            'exempt' => $booking->children + $booking->infants,
            'taxable_from_age' => $rule->taxableFromAge,
            'nights' => $nights,
            'per_adult_night_cents' => $rule->perAdultNightCents,
            'cap_cents' => $rule->capPerStayCents,
            'total_cents' => $total,
        ];
    }

    /**
     * Règle applicable à une date.
     *
     * Sans règle versionnée saisie, la configuration en tient lieu : c'est le
     * barème courant, non daté, que le propriétaire a renseigné.
     */
    private function ruleFor(string $day): TouristTaxRule
    {
        $stored = $this->rules?->applicableOn($day, $this->classification());
        if ($stored !== null) {
            return $stored;
        }

        return new TouristTaxRule(
            0,
            trim($this->settings->string('tax.territory')),
            $this->classification(),
            // Un barème de configuration n'a pas de date d'effet : il vaut
            // depuis toujours, jusqu'à ce qu'une règle datée le remplace.
            '0001-01-01',
            null,
            max(0, $this->settings->money('tax.tourist_per_adult_night')),
            max(0, $this->settings->money('tax.tourist_cap_per_stay')),
            18,
            '',
            '',
        );
    }
}
