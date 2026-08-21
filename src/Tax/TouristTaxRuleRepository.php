<?php

declare(strict_types=1);

namespace SecondStay\Tax;

use SecondStay\Database\Database;

final class TouristTaxRuleRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Règle applicable à une date, pour un classement donné.
     *
     * La plus récemment entrée en vigueur gagne : deux règles qui se
     * recouvrent sont une erreur de saisie, pas une ambiguïté à trancher au
     * hasard.
     */
    public function applicableOn(string $day, string $classification): ?TouristTaxRule
    {
        // Deux paramètres distincts pour la même date : les requêtes préparées
        // ne réutilisent pas un placeholder nommé.
        $row = $this->database->fetchOne(
            'SELECT * FROM `tourist_tax_rule` '
            . 'WHERE `classification` = :classification '
            . 'AND `effective_from` <= :from_day '
            . 'AND (`effective_to` IS NULL OR `effective_to` >= :to_day) '
            . 'ORDER BY `effective_from` DESC, `id` DESC LIMIT 1',
            ['from_day' => $day, 'to_day' => $day, 'classification' => $classification]
        );

        return $row === null ? null : TouristTaxRule::fromRow($row);
    }

    public function find(int $id): ?TouristTaxRule
    {
        $row = $this->database->fetchOne('SELECT * FROM `tourist_tax_rule` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : TouristTaxRule::fromRow($row);
    }

    /**
     * @return list<TouristTaxRule>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): TouristTaxRule => TouristTaxRule::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `tourist_tax_rule` ORDER BY `classification`, `effective_from` DESC, `id` DESC'
            )
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->database->insert('tourist_tax_rule', $data + ['created_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function delete(int $id): void
    {
        $this->database->delete('tourist_tax_rule', ['id' => $id]);
    }

    /**
     * Règles qui se recouvrent pour un même classement.
     *
     * Elles ne sont pas refusées à la saisie — un barème peut légitimement
     * être corrigé — mais elles sont **signalées** : sans cela, deux barèmes
     * concurrents produiraient un montant qui dépend de l'ordre des lignes.
     *
     * @return list<array{0: TouristTaxRule, 1: TouristTaxRule}>
     */
    public function overlaps(): array
    {
        $byClassification = [];
        foreach ($this->all() as $rule) {
            $byClassification[$rule->classification][] = $rule;
        }

        $overlaps = [];
        foreach ($byClassification as $rules) {
            $count = count($rules);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($this->overlap($rules[$i], $rules[$j])) {
                        $overlaps[] = [$rules[$i], $rules[$j]];
                    }
                }
            }
        }

        return $overlaps;
    }

    private function overlap(TouristTaxRule $a, TouristTaxRule $b): bool
    {
        $aEnd = $a->effectiveTo ?? '9999-12-31';
        $bEnd = $b->effectiveTo ?? '9999-12-31';

        return $a->effectiveFrom <= $bEnd && $b->effectiveFrom <= $aEnd;
    }
}
