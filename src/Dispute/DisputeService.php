<?php

declare(strict_types=1);

namespace SecondStay\Dispute;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Contract\ContractRepository;
use SecondStay\I18n\Locales;
use SecondStay\Incident\IncidentRepository;
use SecondStay\Inspection\InspectionKind;
use SecondStay\Inspection\InspectionRepository;
use SecondStay\Logging\Logger;
use SecondStay\Payment\HoldStatus;
use SecondStay\Payment\PaymentKind;
use SecondStay\Payment\PaymentRepository;

/**
 * Litiges.
 *
 * Un litige n'invente aucune information : il **rassemble** ce que le produit
 * a déjà collecté — caution détenue, état des lieux de départ, incidents,
 * contrat accepté — pour que la discussion s'appuie sur des faits datés plutôt
 * que sur des souvenirs.
 *
 * Deux garde-fous :
 *
 * 1. la retenue réclamée ne peut pas dépasser la caution réellement détenue :
 *    réclamer plus que ce que l'on tient n'aurait aucun sens ;
 * 2. clore un litige exige un montant réglé et une explication. Un litige
 *    « résolu » sans dire comment ne vaut pas mieux qu'un litige ouvert.
 */
final class DisputeService
{
    public function __construct(
        private readonly DisputeRepository $disputes,
        private readonly PaymentRepository $payments,
        private readonly InspectionRepository $inspections,
        private readonly IncidentRepository $incidents,
        private readonly ContractRepository $contracts,
        private readonly Logger $logger,
        private readonly ?BookingEventRepository $bookingEvents = null,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Ouvre un litige sur un séjour.
     *
     * @return array{ok: bool, dispute: Dispute|null, error: string}
     */
    public function open(
        Booking $booking,
        string $kind,
        int $claimedCents,
        string $summary,
        string $locale,
        ?User $actor = null,
    ): array {
        $kind = in_array($kind, Dispute::KINDS, true) ? $kind : 'other';
        $summary = trim($summary);

        if ($summary === '') {
            return ['ok' => false, 'dispute' => null, 'error' => 'dispute.error.summary_required'];
        }

        $held = $this->depositHeldCents($booking);
        if ($kind === 'deposit' && $claimedCents > $held) {
            return ['ok' => false, 'dispute' => null, 'error' => 'dispute.error.above_deposit'];
        }

        if ($claimedCents < 0) {
            return ['ok' => false, 'dispute' => null, 'error' => 'dispute.error.amount'];
        }

        $id = $this->disputes->open($booking->id, $kind, [
            'claimed_cents' => $claimedCents,
            'currency' => $booking->currency,
            'summary' => mb_substr($summary, 0, 190),
            'locale' => Locales::isSupported($locale) ? $locale : $booking->locale,
            'opened_by' => $actor?->id,
        ]);

        if ($id === 0) {
            return ['ok' => false, 'dispute' => null, 'error' => 'dispute.error.already_open'];
        }

        $this->disputes->addEvent(
            $id,
            'opened',
            $summary,
            $actor?->id,
            $actor === null ? '' : $actor->displayName(),
        );

        $this->bookingEvents?->record($booking->id, 'dispute_opened', [
            'dispute' => $id,
            'kind' => $kind,
        ], $actor?->id, $actor === null ? '' : $actor->displayName());

        $this->audit?->record('dispute.opened', 'dispute', (string) $id, null, [
            'booking' => $booking->id,
            'kind' => $kind,
            'claimed_cents' => $claimedCents,
        ], $actor?->id, $actor === null ? '' : $actor->email);

        $this->logger->info('dispute', 'Litige ouvert', ['booking' => $booking->id, 'kind' => $kind]);

        return ['ok' => true, 'dispute' => $this->disputes->find($id), 'error' => ''];
    }

    /**
     * Change l'état d'un litige.
     *
     * @return array{ok: bool, error: string}
     */
    public function transition(
        Dispute $dispute,
        DisputeStatus $target,
        int $settledCents,
        string $resolution,
        ?User $actor = null,
    ): array {
        if (!$dispute->status->canMoveTo($target)) {
            return ['ok' => false, 'error' => 'dispute.error.transition'];
        }

        $data = ['status' => $target->value];

        if ($target === DisputeStatus::Resolved) {
            if (trim($resolution) === '') {
                return ['ok' => false, 'error' => 'dispute.error.resolution_required'];
            }

            if ($settledCents < 0 || $settledCents > $dispute->claimedCents) {
                // Régler plus que ce qui était réclamé n'est pas un règlement,
                // c'est une nouvelle réclamation.
                return ['ok' => false, 'error' => 'dispute.error.settlement'];
            }

            $data['settled_cents'] = $settledCents;
            $data['resolution'] = mb_substr(trim($resolution), 0, 8000);
            $data['resolved_at'] = gmdate('Y-m-d H:i:s');
        } else {
            // Rouvrir efface la résolution : la garder ferait croire à un
            // litige clos alors qu'il ne l'est plus.
            $data['resolved_at'] = null;
        }

        $this->disputes->update($dispute->id, $data);
        $this->disputes->addEvent(
            $dispute->id,
            $target->value,
            trim($resolution),
            $actor?->id,
            $actor === null ? '' : $actor->displayName(),
        );

        $this->audit?->record('dispute.' . $target->value, 'dispute', (string) $dispute->id, [
            'status' => $dispute->status->value,
        ], [
            'status' => $target->value,
            'settled_cents' => $data['settled_cents'] ?? $dispute->settledCents,
        ], $actor?->id, $actor === null ? '' : $actor->email);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Ajoute un échange à l'historique.
     *
     * @return array{ok: bool, error: string}
     */
    public function comment(Dispute $dispute, string $note, ?User $actor = null): array
    {
        $note = trim($note);
        if ($note === '') {
            return ['ok' => false, 'error' => 'dispute.error.note_required'];
        }

        $this->disputes->addEvent(
            $dispute->id,
            'comment',
            $note,
            $actor?->id,
            $actor === null ? '' : $actor->displayName(),
        );
        $this->disputes->update($dispute->id, []);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Pièces déjà collectées par le produit, utiles à la discussion.
     *
     * @return array{
     *     deposit_held_cents: int,
     *     checkout_completed: bool,
     *     checkout_anomalies: int,
     *     photos: int,
     *     incidents: int,
     *     contract_accepted: bool
     * }
     */
    public function evidenceFor(Booking $booking): array
    {
        $checkout = $this->inspections->findFor($booking->id, InspectionKind::Checkout, $booking->locale);

        $photos = 0;
        $anomalies = 0;
        if ($checkout !== null) {
            foreach ($checkout->entries as $entry) {
                $photos += count($entry->photoIds);
                if ($entry->state->isAnomaly()) {
                    $anomalies++;
                }
            }
        }

        return [
            'deposit_held_cents' => $this->depositHeldCents($booking),
            'checkout_completed' => $checkout !== null && $checkout->status->isCompleted(),
            'checkout_anomalies' => $anomalies,
            'photos' => $photos,
            'incidents' => count($this->incidents->forBooking($booking->id, $booking->locale)),
            'contract_accepted' => $this->contracts->forBooking($booking->id) !== null,
        ];
    }

    /**
     * Caution réellement détenue sur ce séjour.
     */
    public function depositHeldCents(Booking $booking): int
    {
        $payment = $this->payments->findKind($booking->id, PaymentKind::SecurityDeposit);
        if ($payment === null) {
            return 0;
        }

        return match ($payment->holdStatus) {
            HoldStatus::Received, HoldStatus::ToReturn, HoldStatus::PartiallyRetained => $payment->netCents(),
            default => 0,
        };
    }
}
