<?php

declare(strict_types=1);

namespace SecondStay\Payment;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Composant financier d'un séjour.
 *
 * Les montants sont des entiers de centimes ; le montant remboursé est suivi
 * séparément du montant dû, de sorte qu'un remboursement partiel reste
 * lisible.
 */
final class Payment
{
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly PaymentKind $kind,
        public readonly PaymentStatus $status,
        public readonly int $amountCents,
        public readonly int $refundedCents,
        public readonly string $currency,
        public readonly string $method,
        public readonly ?string $dueOn,
        public readonly string $provider,
        public readonly string $providerReference,
        public readonly string $description,
        public readonly HoldStatus $holdStatus,
        public readonly string $createdAt,
        public readonly ?string $paidAt,
        public readonly ?string $refundedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['booking_id'],
            PaymentKind::fromString((string) $row['kind']),
            PaymentStatus::fromString((string) $row['status']),
            (int) $row['amount_cents'],
            (int) $row['refunded_cents'],
            (string) $row['currency'],
            (string) $row['method'],
            $row['due_on'] === null ? null : (string) $row['due_on'],
            (string) $row['provider'],
            (string) ($row['provider_reference'] ?? ''),
            (string) $row['description'],
            HoldStatus::fromString((string) $row['hold_status']),
            (string) $row['created_at'],
            $row['paid_at'] === null ? null : (string) $row['paid_at'],
            $row['refunded_at'] === null ? null : (string) $row['refunded_at'],
        );
    }

    /**
     * Échéance sous forme de date, pour un affichage localisé.
     *
     * L'échéance est un jour calendaire, sans heure ni fuseau : elle est donc
     * stockée comme telle et n'est convertie qu'au moment de l'afficher.
     */
    public function dueDate(): ?DateTimeImmutable
    {
        return $this->dueOn === null
            ? null
            : new DateTimeImmutable($this->dueOn . ' 00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * Montant réellement acquis : ce qui a été payé moins ce qui a été rendu.
     */
    public function netCents(): int
    {
        return $this->status->isSettled() ? max(0, $this->amountCents - $this->refundedCents) : 0;
    }

    public function outstandingCents(): int
    {
        return $this->status->isSettled() ? 0 : $this->amountCents;
    }

    public function isOverdue(?string $today = null): bool
    {
        if ($this->dueOn === null || $this->status->isSettled()) {
            return false;
        }

        return $this->dueOn < ($today ?? gmdate('Y-m-d'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'amount_cents' => $this->amountCents,
            'refunded_cents' => $this->refundedCents,
            'net_cents' => $this->netCents(),
            'currency' => $this->currency,
            'due_on' => $this->dueOn,
            'hold_status' => $this->holdStatus->value,
        ];
    }
}
