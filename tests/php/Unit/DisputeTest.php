<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Dispute\Dispute;
use SecondStay\Dispute\DisputeEvent;
use SecondStay\Dispute\DisputeStatus;

/**
 * Cycle de vie d'un litige (ROADMAP.md itération 14).
 */
final class DisputeTest extends TestCase
{
    public function testAStatusIsReadFromItsStringForm(): void
    {
        self::assertSame(DisputeStatus::Discussing, DisputeStatus::fromString(' Discussing '));
        self::assertSame(DisputeStatus::Resolved, DisputeStatus::fromString('RESOLVED'));
    }

    public function testAnUnknownStatusFallsBackToOpen(): void
    {
        // Un état inconnu ne doit jamais fermer un litige par accident.
        self::assertSame(DisputeStatus::Open, DisputeStatus::fromString('clos'));
        self::assertSame(DisputeStatus::Open, DisputeStatus::fromString(''));
    }

    public function testAnOpenDisputeCanBeDiscussedOrResolved(): void
    {
        self::assertTrue(DisputeStatus::Open->canMoveTo(DisputeStatus::Discussing));
        self::assertTrue(DisputeStatus::Open->canMoveTo(DisputeStatus::Resolved));
    }

    public function testADisputeNeverMovesToItsOwnState(): void
    {
        foreach (DisputeStatus::cases() as $status) {
            self::assertFalse($status->canMoveTo($status), $status->value);
        }
    }

    public function testAResolvedDisputeOnlyReopensIntoDiscussion(): void
    {
        self::assertSame([DisputeStatus::Discussing], DisputeStatus::Resolved->allowedTransitions());
        self::assertFalse(DisputeStatus::Resolved->canMoveTo(DisputeStatus::Open));
    }

    public function testADiscussionNeverGoesBackToOpen(): void
    {
        self::assertSame([DisputeStatus::Resolved], DisputeStatus::Discussing->allowedTransitions());
    }

    public function testOnlyTheResolvedStateIsResolved(): void
    {
        self::assertTrue(DisputeStatus::Resolved->isResolved());
        self::assertFalse(DisputeStatus::Open->isResolved());
        self::assertFalse(DisputeStatus::Discussing->isResolved());
    }

    public function testEveryStatusCarriesALabelAndABadge(): void
    {
        foreach (DisputeStatus::cases() as $status) {
            self::assertSame('dispute.status.' . $status->value, $status->labelKey());
            self::assertStringStartsWith('text-bg-', $status->badgeClass());
        }
    }

    private function dispute(int $claimed, int $settled, string $status = 'resolved'): Dispute
    {
        return Dispute::fromRow([
            'id' => 7,
            'booking_id' => 3,
            'kind' => 'deposit',
            'status' => $status,
            'claimed_cents' => $claimed,
            'settled_cents' => $settled,
            'currency' => 'EUR',
            'summary' => 'Carrelage fêlé',
            'resolution' => 'Moitié retenue',
            'locale' => 'fr',
            'opened_by' => 1,
            'opened_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-04 09:00:00',
            'resolved_at' => '2026-06-04 09:00:00',
        ]);
    }

    public function testWhatIsWaivedIsTheDifference(): void
    {
        self::assertSame(30_000, $this->dispute(50_000, 20_000)->waivedCents());
    }

    public function testNothingIsWaivedWhenTheClaimIsSettledInFull(): void
    {
        self::assertSame(0, $this->dispute(50_000, 50_000)->waivedCents());
    }

    public function testWaivedNeverGoesNegative(): void
    {
        self::assertSame(0, $this->dispute(10_000, 25_000)->waivedCents());
    }

    public function testADisputeIsOpenUntilItIsResolved(): void
    {
        self::assertTrue($this->dispute(1, 0, 'open')->isOpen());
        self::assertTrue($this->dispute(1, 0, 'discussing')->isOpen());
        self::assertFalse($this->dispute(1, 0, 'resolved')->isOpen());
    }

    public function testDatesAreReadAsUtc(): void
    {
        $dispute = $this->dispute(1, 0);

        self::assertSame('2026-06-01T10:00:00+00:00', $dispute->openedDate()->format('c'));
        self::assertSame('2026-06-04T09:00:00+00:00', $dispute->resolvedDate()?->format('c'));
    }

    public function testAnUnresolvedDisputeHasNoResolutionDate(): void
    {
        $dispute = Dispute::fromRow([
            'id' => 8,
            'booking_id' => 3,
            'kind' => 'damage',
            'status' => 'open',
            'claimed_cents' => 0,
            'settled_cents' => 0,
            'currency' => 'EUR',
            'summary' => 'Store cassé',
            'resolution' => null,
            'locale' => 'fr',
            'opened_by' => null,
            'opened_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-01 10:00:00',
            'resolved_at' => null,
        ]);

        self::assertNull($dispute->resolvedDate());
        self::assertSame('', $dispute->resolution);
        self::assertNull($dispute->openedBy);
    }

    public function testEveryKindHasItsOwnLabelKey(): void
    {
        $keys = [];
        foreach (Dispute::KINDS as $kind) {
            $dispute = $this->dispute(1, 0);
            $keys[] = Dispute::fromRow([
                'id' => 1,
                'booking_id' => $dispute->bookingId,
                'kind' => $kind,
                'status' => 'open',
                'claimed_cents' => 0,
                'settled_cents' => 0,
                'currency' => 'EUR',
                'summary' => 's',
                'resolution' => '',
                'locale' => 'fr',
                'opened_by' => null,
                'opened_at' => '2026-06-01 10:00:00',
                'updated_at' => '2026-06-01 10:00:00',
                'resolved_at' => null,
            ])->kindLabelKey();
        }

        self::assertSame(
            ['dispute.kind.deposit', 'dispute.kind.damage', 'dispute.kind.payment', 'dispute.kind.other'],
            $keys
        );
    }

    public function testAnEventCarriesItsLabelKeyAndDate(): void
    {
        $event = DisputeEvent::fromRow([
            'id' => 1,
            'dispute_id' => 7,
            'type' => 'comment',
            'note' => 'Photos envoyées',
            'actor_id' => 2,
            'actor_label' => 'Marie',
            'created_at' => '2026-06-02 08:30:00',
        ]);

        self::assertSame('dispute.event.comment', $event->labelKey());
        self::assertSame('2026-06-02T08:30:00+00:00', $event->createdDate()->format('c'));
        self::assertSame('Marie', $event->actorLabel);
    }
}
