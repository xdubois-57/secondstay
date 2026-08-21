<?php

declare(strict_types=1);

namespace SecondStay\Tax;

use SecondStay\Database\Database;

/**
 * Contexte de calcul figé avec un séjour (SPECIFICATIONS.md §63).
 *
 * Un barème change ; un séjour déjà engagé, non. Sans cet enregistrement,
 * expliquer le montant d'une taxe facturée l'an dernier reviendrait à
 * recalculer avec le barème d'aujourd'hui — c'est-à-dire à ne rien expliquer
 * du tout.
 */
final class TouristTaxContextRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Fige le contexte, une seule fois par séjour.
     *
     * @param array<string, mixed> $context
     */
    public function freeze(int $bookingId, ?int $ruleId, int $amountCents, array $context): void
    {
        if ($this->find($bookingId) !== null) {
            return;
        }

        $this->database->insert('booking_tax_context', [
            'booking_id' => $bookingId,
            'rule_id' => $ruleId,
            'amount_cents' => $amountCents,
            'context' => (string) json_encode($context, JSON_UNESCAPED_UNICODE),
            'computed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{rule_id: ?int, amount_cents: int, context: array<string, mixed>, computed_at: string}|null
     */
    public function find(int $bookingId): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `booking_tax_context` WHERE `booking_id` = :booking',
            ['booking' => $bookingId]
        );

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed>|null $context */
        $context = json_decode((string) $row['context'], true);

        return [
            'rule_id' => $row['rule_id'] === null ? null : (int) $row['rule_id'],
            'amount_cents' => (int) $row['amount_cents'],
            'context' => is_array($context) ? $context : [],
            'computed_at' => (string) $row['computed_at'],
        ];
    }
}
