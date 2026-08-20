<?php

declare(strict_types=1);

namespace SecondStay\Operations;

use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Database\Migrator;
use SecondStay\Imap\InboundMailRepository;
use SecondStay\Incident\IncidentRepository;
use SecondStay\Payment\PaymentRepository;

/**
 * Tableau « À faire » (SPECIFICATIONS.md §50).
 *
 * Il ne liste que ce qui **réclame une décision humaine** : une demande à
 * valider, une échéance dépassée, une caution à restituer, un courrier qu'aucune
 * règle n'a su rattacher, une migration en attente. Un tableau qui listerait
 * tout ce qui existe ne serait plus lu.
 */
final class TodoService
{
    /** Horizon des séjours à préparer, en jours. */
    public const HORIZON_DAYS = 14;

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly PaymentRepository $payments,
        private readonly InboundMailRepository $mails,
        private readonly ChecklistService $checklists,
        private readonly ?Migrator $migrator = null,
        private readonly ?IncidentRepository $incidents = null,
    ) {
    }

    /**
     * @return list<array{code: string, key: string, severity: string, count: int, route: string, params: array<string, string|int>}>
     */
    public function items(?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $items = [];

        $toConfirm = $this->bookings->listing([BookingStatus::ToConfirm, BookingStatus::Request]);
        if ($toConfirm !== []) {
            $items[] = $this->item('bookings_to_confirm', 'warning', count($toConfirm), 'admin.bookings');
        }

        $overdue = $this->payments->overdue($today);
        if ($overdue !== []) {
            $items[] = $this->item('payments_overdue', 'danger', count($overdue), 'admin.payments');
        }

        $held = array_values(array_filter(
            $this->payments->heldDeposits(),
            static fn (array $row): bool => $row['payment']->holdStatus->value === 'to_return'
        ));
        if ($held !== []) {
            $items[] = $this->item('deposits_to_return', 'warning', count($held), 'admin.payments');
        }

        $unlinked = $this->mails->unlinked(50);
        if ($unlinked !== []) {
            $items[] = $this->item('mail_unlinked', 'info', count($unlinked), 'admin.mailbox');
        }

        $unprepared = $this->unpreparedStays($today);
        if ($unprepared !== []) {
            $items[] = $this->item('stays_to_prepare', 'warning', count($unprepared), 'admin.operations');
        }

        // Un incident ouvert réclame une décision : c'est exactement ce que
        // ce tableau doit montrer (SPECIFICATIONS.md §50).
        $openIncidents = $this->incidents?->countOpen() ?? 0;
        if ($openIncidents > 0) {
            $items[] = $this->item('incidents_open', 'danger', $openIncidents, 'admin.incidents');
        }

        if ($this->migrator !== null && $this->migrator->pending() !== []) {
            $items[] = $this->item('migrations_pending', 'danger', count($this->migrator->pending()), 'admin.diagnostics');
        }

        return $items;
    }

    /**
     * Séjours proches dont la préparation n'est pas terminée.
     *
     * @return list<array{booking: Booking, outstanding: list<ChecklistItem>}>
     */
    public function unpreparedStays(?string $today = null, int $horizonDays = self::HORIZON_DAYS): array
    {
        $today ??= gmdate('Y-m-d');
        $limit = (new \DateTimeImmutable($today . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify('+' . max(1, $horizonDays) . ' days')
            ->format('Y-m-d');

        $stays = [];

        foreach ($this->bookings->arrivingBetween($today, $limit) as $booking) {
            $outstanding = array_values(array_filter(
                $this->checklists->before($booking),
                static fn (ChecklistItem $item): bool => $item->needsAction()
            ));

            if ($outstanding !== []) {
                $stays[] = ['booking' => $booking, 'outstanding' => $outstanding];
            }
        }

        return $stays;
    }

    /**
     * @param array<string, string|int> $params
     *
     * @return array{code: string, key: string, severity: string, count: int, route: string, params: array<string, string|int>}
     */
    private function item(string $code, string $severity, int $count, string $route, array $params = []): array
    {
        return [
            'code' => $code,
            // Le tableau de bord et la page d'exploitation affichent la même
            // liste : une seule clé de traduction, une seule vérité.
            'key' => 'operations.todo.' . $code,
            'severity' => $severity,
            'count' => $count,
            'route' => $route,
            'params' => $params,
        ];
    }
}
