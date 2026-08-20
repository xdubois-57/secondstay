<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use InvalidArgumentException;
use SecondStay\Availability\AvailabilityBlockRepository;
use SecondStay\Availability\AvailabilityService;
use SecondStay\Booking\StayRules;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Pricing\DateRange;
use SecondStay\Pricing\RateRepository;
use SecondStay\Support\Money;

/**
 * Tarifs par nuit et indisponibilités.
 *
 * L'écran travaille sur des plages : appliquer un tarif à un mois entier est
 * le geste courant, saisir nuit par nuit resterait inutilisable.
 */
final class AdminPricingController extends AdminController
{
    protected function section(): string
    {
        return 'pricing';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        return $this->renderPricing($context, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveRates(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $request = $context->request;

        try {
            $range = DateRange::fromStrings(
                (string) $request->input('from', ''),
                (string) $request->input('to', '')
            );
        } catch (InvalidArgumentException) {
            return $this->renderPricing($context, ['from' => 'booking.error.invalid_date'], 422);
        }

        // La saisie porte sur des nuits : la dernière nuit choisie est incluse.
        $range = DateRange::fromNights($range->arrivalKey(), $range->departureKey());

        if (!$range->isValid()) {
            return $this->renderPricing($context, ['from' => 'booking.error.invalid_range'], 422);
        }

        $rates = $this->container->get(RateRepository::class);

        if ($request->input('action') === 'clear') {
            $cleared = $rates->clearRange($range);
            $this->audit()->record('pricing.rates_cleared', 'rate_override', (string) $range, null, [
                'nights' => $cleared,
            ], $user->id, $user->email);
            $this->flashSuccess('admin.pricing.rates_cleared');

            return $this->redirectToRoute($context, 'admin.pricing');
        }

        $price = Money::parse((string) $request->input('price', ''));
        if ($price === null || $price < 0) {
            return $this->renderPricing($context, ['price' => 'admin.pricing.error.price'], 422);
        }

        $minNights = (int) $request->input('min_nights', '0');
        $changed = $rates->applyToRange(
            $range,
            $price,
            $minNights > 0 ? min(90, $minNights) : null,
            (string) $request->input('note', '')
        );

        $this->audit()->record('pricing.rates_applied', 'rate_override', (string) $range, null, [
            'nights' => $changed,
            'price_cents' => $price,
        ], $user->id, $user->email);

        $this->flashSuccess('admin.pricing.rates_applied');

        return $this->redirectToRoute($context, 'admin.pricing');
    }

    /**
     * @param array<string, string> $params
     */
    public function createBlock(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();
        $request = $context->request;

        try {
            $range = DateRange::fromNights(
                (string) $request->input('start', ''),
                (string) $request->input('end', '')
            );
        } catch (InvalidArgumentException) {
            return $this->renderPricing($context, ['start' => 'booking.error.invalid_date'], 422);
        }

        if (!$range->isValid()) {
            return $this->renderPricing($context, ['start' => 'booking.error.invalid_range'], 422);
        }

        $id = $this->container->get(AvailabilityBlockRepository::class)->create(
            $range,
            (string) $request->input('kind', AvailabilityBlockRepository::KIND_OWNER),
            (string) $request->input('label', ''),
            $user->id,
        );

        $this->audit()->record('availability.blocked', 'availability_block', (string) $id, null, [
            'range' => (string) $range,
        ], $user->id, $user->email);

        $this->flashSuccess('admin.pricing.block_created');

        return $this->redirectToRoute($context, 'admin.pricing');
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteBlock(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $id = (int) ($params['id'] ?? 0);
        $removed = $this->container->get(AvailabilityBlockRepository::class)->delete($id);

        if ($removed) {
            $this->audit()->record(
                'availability.unblocked',
                'availability_block',
                (string) $id,
                null,
                null,
                $user->id,
                $user->email
            );
            $this->flashSuccess('admin.pricing.block_deleted');
        } else {
            $this->flashError('admin.pricing.block_not_found');
        }

        return $this->redirectToRoute($context, 'admin.pricing');
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderPricing(RequestContext $context, array $errors, int $status = 200): Response
    {
        $availability = $this->container->get(AvailabilityService::class);
        $month = $availability->normaliseMonth($context->request->query('month'));
        $calendar = $availability->month($month);

        return $this->renderAdmin('admin/pricing.html.twig', [
            'meta_title' => $this->trans('admin.pricing.title'),
            'calendar' => $calendar,
            'calendar_month' => DateRange::fromStrings($calendar['first_day'], $calendar['first_day'])->arrival,
            'blocks' => $this->container->get(AvailabilityBlockRepository::class)
                ->upcoming($availability->today()->format('Y-m-d')),
            'kinds' => AvailabilityBlockRepository::KINDS,
            'rules' => $this->container->get(StayRules::class)->summary(),
            'default_night_price' => $this->settings()->money('pricing.default_night_price'),
            'errors' => $errors,
        ], $status);
    }
}
