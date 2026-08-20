<?php

declare(strict_types=1);

namespace SecondStay\Operations;

use SecondStay\Booking\SubStatus;

/**
 * Une ligne de checklist.
 *
 * Deux natures se côtoient : les lignes **dérivées** de l'état du séjour —
 * contrat, acompte, solde, caution — qui se lisent là où elles vivent, et les
 * lignes **cochées** par un humain, qui n'existent nulle part ailleurs.
 * Dupliquer les premières en base créerait deux vérités susceptibles de
 * diverger.
 */
final class ChecklistItem
{
    public function __construct(
        public readonly string $code,
        public readonly TaskPhase $phase,
        public readonly SubStatus $status,
        public readonly bool $manual,
        public readonly ?int $taskId = null,
        public readonly string $note = '',
        public readonly ?string $doneAt = null,
    ) {
    }

    public function labelKey(): string
    {
        return 'operations.item.' . $this->code;
    }

    public function isDone(): bool
    {
        return $this->status === SubStatus::Done;
    }

    /**
     * Une ligne qui réclame réellement une action.
     *
     * Une ligne sans objet — pas de caution demandée, pas de ménage — n'est
     * pas « en retard » : elle ne concerne simplement pas ce séjour.
     */
    public function needsAction(): bool
    {
        return match ($this->status) {
            SubStatus::Pending, SubStatus::Partial, SubStatus::Failed => true,
            default => false,
        };
    }
}
