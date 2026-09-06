<?php

declare(strict_types=1);

namespace SecondStay\Booking;

use InvalidArgumentException;
use SecondStay\Availability\AvailabilityService;
use SecondStay\Pricing\DateRange;
use SecondStay\Pricing\PriceCalculator;

/**
 * Devis complet d'un séjour : règles, disponibilité et prix en une réponse.
 *
 * C'est ce que consomment la page de disponibilités et, à partir de
 * l'itération 6, le parcours de réservation. Le total affiché en direct et le
 * total facturé proviennent donc du même calcul.
 */
final class QuoteService
{
    public function __construct(
        private readonly StayRules $rules,
        private readonly AvailabilityService $availability,
        private readonly PriceCalculator $prices,
    ) {
    }

    /**
     * @param array{arrival?: string, departure?: string, adults?: int, children?: int, infants?: int,
     *     cleaning?: bool} $input
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     conflicts: list<string>,
     *     quote: array<string, mixed>|null,
     *     rules: array<string, mixed>
     * }
     */
    public function evaluate(array $input): array
    {
        $rules = $this->rules->summary();

        try {
            $range = DateRange::fromStrings(
                (string) ($input['arrival'] ?? ''),
                (string) ($input['departure'] ?? '')
            );
        } catch (InvalidArgumentException $exception) {
            return [
                'ok' => false,
                'errors' => [$exception->getMessage()],
                'conflicts' => [],
                'quote' => null,
                'rules' => $rules,
            ];
        }

        $errors = $this->rules->validateRange($range);
        $errors = array_merge(
            $errors,
            $this->rules->validateGuests(
                (int) ($input['adults'] ?? 0),
                (int) ($input['children'] ?? 0),
                (int) ($input['infants'] ?? 0),
            )
        );

        $conflicts = $range->isValid() ? $this->availability->conflictingNights($range) : [];
        if ($conflicts !== []) {
            $errors[] = 'booking.error.unavailable';
        }

        // Le devis est calculé dès que la plage est cohérente : le visiteur
        // voit le prix même si sa composition de groupe doit être corrigée.
        $quote = $range->isValid()
            ? $this->prices->quote($range, isset($input['cleaning']) ? (bool) $input['cleaning'] : null)
            : null;

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'conflicts' => $conflicts,
            'quote' => $quote?->toArray(),
            'rules' => $rules,
        ];
    }
}
