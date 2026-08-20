<?php

declare(strict_types=1);

namespace SecondStay\Operations;

use SecondStay\Booking\Booking;
use SecondStay\Booking\SubStatus;
use SecondStay\Calendar\CalendarService;
use SecondStay\Contract\ContractRepository;
use SecondStay\Payment\HoldStatus;
use SecondStay\Payment\PaymentKind;
use SecondStay\Payment\PaymentRepository;
use SecondStay\Payment\PaymentStatus;

/**
 * Checklists d'un séjour (SPECIFICATIONS.md §49).
 *
 * Les lignes dérivées sont **lues** dans l'état du séjour, jamais recopiées :
 * un contrat accepté est accepté, un acompte encaissé est encaissé, et une
 * checklist ne peut pas prétendre le contraire. Seules les lignes proprement
 * opérationnelles — accès remis, ménage fait, état des lieux — sont cochées
 * par un humain et vivent en base.
 */
final class ChecklistService
{
    /** Tâches cochées manuellement, avant le séjour. */
    public const BEFORE_MANUAL = ['cleaning_scheduled', 'access_shared', 'welcome_sent'];

    /** Tâches cochées manuellement, au départ. */
    public const DEPARTURE_MANUAL = ['inventory_done', 'incidents_reviewed', 'cleaning_done', 'deposit_settled'];

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly ContractRepository $contracts,
        private readonly TaskRepository $tasks,
        private readonly CalendarService $calendar,
    ) {
    }

    /**
     * Checklist complète d'un séjour, dérivées et manuelles mêlées.
     *
     * @return list<ChecklistItem>
     */
    public function forBooking(Booking $booking): array
    {
        return array_merge($this->before($booking), $this->departure($booking));
    }

    /**
     * @return list<ChecklistItem>
     */
    public function before(Booking $booking): array
    {
        $stored = $this->tasks->forBooking($booking->id);

        $items = [
            $this->derived('contract', TaskPhase::Before, $this->contractStatus($booking)),
            $this->derived('deposit', TaskPhase::Before, $this->paymentStatus($booking, PaymentKind::Deposit)),
            $this->derived('balance', TaskPhase::Before, $this->paymentStatus($booking, PaymentKind::Balance)),
            $this->derived('security_deposit', TaskPhase::Before, $this->holdStatus($booking)),
            $this->derived('manager', TaskPhase::Before, $this->managerStatus($booking)),
        ];

        foreach (self::BEFORE_MANUAL as $code) {
            $items[] = $this->manual($code, TaskPhase::Before, $stored[$code] ?? null);
        }

        return $items;
    }

    /**
     * @return list<ChecklistItem>
     */
    public function departure(Booking $booking): array
    {
        $stored = $this->tasks->forBooking($booking->id);

        $items = [];
        foreach (self::DEPARTURE_MANUAL as $code) {
            $items[] = $this->manual($code, TaskPhase::Departure, $stored[$code] ?? null);
        }

        return $items;
    }

    /**
     * Coche ou décoche une tâche manuelle.
     *
     * @return array{ok: bool, error: string}
     */
    public function toggle(Booking $booking, string $code, bool $done, ?int $actorId, string $note = ''): array
    {
        $phase = $this->phaseOf($code);
        if ($phase === null) {
            // Une checklist n'accepte que ses propres lignes : accepter un
            // code arbitraire laisserait n'importe qui écrire en base.
            return ['ok' => false, 'error' => 'operations.error.unknown_item'];
        }

        $this->tasks->ensure($booking->id, $code, $phase);
        $this->tasks->setDone($booking->id, $code, $done, $actorId, $note);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Lignes réclamant encore une action, pour un séjour.
     *
     * @return list<ChecklistItem>
     */
    public function outstanding(Booking $booking): array
    {
        return array_values(array_filter(
            $this->forBooking($booking),
            static fn (ChecklistItem $item): bool => $item->needsAction()
        ));
    }

    /**
     * Progression d'un séjour, lignes sans objet exclues.
     *
     * @return array{done: int, total: int}
     */
    public function progress(Booking $booking): array
    {
        $done = 0;
        $total = 0;

        foreach ($this->forBooking($booking) as $item) {
            if ($item->status === SubStatus::NotApplicable) {
                continue;
            }

            $total++;
            if ($item->isDone()) {
                $done++;
            }
        }

        return ['done' => $done, 'total' => $total];
    }

    public function phaseOf(string $code): ?TaskPhase
    {
        if (in_array($code, self::BEFORE_MANUAL, true)) {
            return TaskPhase::Before;
        }

        if (in_array($code, self::DEPARTURE_MANUAL, true)) {
            return TaskPhase::Departure;
        }

        return null;
    }

    // --- Lignes dérivées -------------------------------------------------------

    private function derived(string $code, TaskPhase $phase, SubStatus $status): ChecklistItem
    {
        return new ChecklistItem($code, $phase, $status, false);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function manual(string $code, TaskPhase $phase, ?array $row): ChecklistItem
    {
        $doneAt = $row === null ? null : ($row['done_at'] === null ? null : (string) $row['done_at']);

        return new ChecklistItem(
            $code,
            $phase,
            $doneAt === null ? SubStatus::Pending : SubStatus::Done,
            true,
            $row === null ? null : (int) $row['id'],
            $row === null ? '' : (string) $row['note'],
            $doneAt,
        );
    }

    private function contractStatus(Booking $booking): SubStatus
    {
        return $this->contracts->forBooking($booking->id) === null ? SubStatus::Pending : SubStatus::Done;
    }

    private function paymentStatus(Booking $booking, PaymentKind $kind): SubStatus
    {
        $payment = $this->payments->findKind($booking->id, $kind);

        if ($payment === null || $payment->amountCents === 0) {
            return SubStatus::NotApplicable;
        }

        return match (true) {
            $payment->status === PaymentStatus::Paid => SubStatus::Done,
            $payment->status->isSettled() => SubStatus::Partial,
            $payment->status === PaymentStatus::Failed => SubStatus::Failed,
            default => SubStatus::Pending,
        };
    }

    private function holdStatus(Booking $booking): SubStatus
    {
        $hold = $this->payments->findKind($booking->id, PaymentKind::SecurityDeposit);

        if ($hold === null || $hold->amountCents === 0) {
            return SubStatus::NotApplicable;
        }

        return match ($hold->holdStatus) {
            HoldStatus::Received, HoldStatus::Returned, HoldStatus::PartiallyRetained => SubStatus::Done,
            HoldStatus::ToReturn => SubStatus::Partial,
            default => SubStatus::Pending,
        };
    }

    /**
     * Un séjour a-t-il un responsable, affecté ou par défaut ?
     */
    private function managerStatus(Booking $booking): SubStatus
    {
        if (!$booking->status->occupiesNights()) {
            return SubStatus::NotApplicable;
        }

        return $this->calendar->managerOf($booking) === null ? SubStatus::Pending : SubStatus::Done;
    }
}
