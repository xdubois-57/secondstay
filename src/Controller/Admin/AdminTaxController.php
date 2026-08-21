<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Support\Money;
use SecondStay\Tax\TouristTaxCalculator;
use SecondStay\Tax\TouristTaxRuleRepository;

/**
 * Barèmes de taxe de séjour, datés (SPECIFICATIONS.md §63).
 *
 * Un barème est voté puis remplacé : l'écran montre donc une liste de règles
 * avec leur période de validité, et signale les recouvrements — deux barèmes
 * concurrents produiraient un montant qui dépend de l'ordre des lignes, ce qui
 * n'est pas une taxe, c'est un tirage au sort.
 */
final class AdminTaxController extends AdminController
{
    protected function section(): string
    {
        return 'tax';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $rules = $this->container->get(TouristTaxRuleRepository::class);
        $calculator = $this->container->get(TouristTaxCalculator::class);

        return $this->renderAdmin('admin/tax.html.twig', [
            'meta_title' => $this->trans('tax.title'),
            'rules' => $rules->all(),
            'overlaps' => $rules->overlaps(),
            'enabled' => $calculator->isEnabled(),
            'classification' => $calculator->classification(),
            'today' => gmdate('Y-m-d'),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $from = $this->date((string) $context->request->input('effective_from', ''));
        if ($from === null) {
            $this->flashError('tax.error.effective_from');

            return $this->redirectToRoute($context, 'admin.tax');
        }

        $to = $this->date((string) $context->request->input('effective_to', ''));
        if ($to !== null && $to < $from) {
            // Une règle qui finirait avant de commencer ne s'appliquerait
            // jamais : la refuser vaut mieux que la laisser dormir en base.
            $this->flashError('tax.error.period');

            return $this->redirectToRoute($context, 'admin.tax');
        }

        $perNight = Money::parse((string) $context->request->input('per_adult_night', '0'));
        $cap = Money::parse((string) $context->request->input('cap_per_stay', '0'));

        if ($perNight === null || $perNight < 0 || $cap === null || $cap < 0) {
            $this->flashError('tax.error.amount');

            return $this->redirectToRoute($context, 'admin.tax');
        }

        $this->container->get(TouristTaxRuleRepository::class)->create([
            'territory' => mb_substr(trim((string) $context->request->input('territory', '')), 0, 120),
            'classification' => mb_substr(
                trim((string) $context->request->input('classification', 'unclassified')),
                0,
                48
            ),
            'effective_from' => $from,
            'effective_to' => $to,
            'per_adult_night_cents' => $perNight,
            'cap_per_stay_cents' => $cap,
            'taxable_from_age' => max(0, min(99, (int) $context->request->input('taxable_from_age', '18'))),
            'source_url' => mb_substr(trim((string) $context->request->input('source_url', '')), 0, 500),
            'notes' => mb_substr(trim((string) $context->request->input('notes', '')), 0, 255),
        ]);

        $this->audit()->record('tax.rule_created', 'tourist_tax_rule', $from, null, [
            'effective_from' => $from,
            'effective_to' => $to,
        ], $user->id, $user->email);

        $this->flashSuccess('tax.rule_created');

        return $this->redirectToRoute($context, 'admin.tax');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $rules = $this->container->get(TouristTaxRuleRepository::class);
        $rule = $rules->find((int) ($params['id'] ?? 0));
        if ($rule === null) {
            throw new NotFoundException('Règle introuvable.');
        }

        $rules->delete($rule->id);

        // Les séjours qui l'ont utilisée gardent leur contexte figé : la
        // suppression d'une règle n'efface aucun calcul déjà rendu.
        $this->audit()->record('tax.rule_deleted', 'tourist_tax_rule', (string) $rule->id, [
            'effective_from' => $rule->effectiveFrom,
        ], null, $user->id, $user->email);

        $this->flashSuccess('tax.rule_deleted');

        return $this->redirectToRoute($context, 'admin.tax');
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date === false ? null : $date->format('Y-m-d');
    }
}
