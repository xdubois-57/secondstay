<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Incident\IncidentSeverity;
use SecondStay\Incident\IncidentStatus;
use SecondStay\Inspection\EntryState;
use SecondStay\Inspection\Inspection;
use SecondStay\Inspection\InspectionEntry;
use SecondStay\Inspection\InspectionKind;
use SecondStay\Inspection\InspectionStatus;
use SecondStay\Inspection\Zone;

/**
 * Règles d'un état des lieux et d'un incident, sans base de données.
 *
 * Ce qui est vérifié ici est exactement ce que la spécification distingue :
 * à l'arrivée on signale, au départ on prouve (SPECIFICATIONS.md §53), et un
 * incident suit un cycle nommé plutôt qu'un champ libre (§54).
 */
final class InspectionTest extends TestCase
{
    // --- Blocage d'un état des lieux ------------------------------------------------

    public function testAnArrivalOnlyNeedsEveryZoneDecided(): void
    {
        $inspection = $this->inspection(InspectionKind::Checkin, [
            $this->entry('kitchen', EntryState::Ok, true, []),
            $this->entry('outdoor', EntryState::Anomaly, false, []),
        ]);

        // Aucune photo, et pourtant rien ne bloque : à l'arrivée, le voyageur
        // signale, il ne prouve pas.
        self::assertSame([], $inspection->blocking());
        self::assertTrue($inspection->isComplete());
        self::assertSame(['done' => 2, 'total' => 2], $inspection->progress());
    }

    public function testAnArrivalIsBlockedByAZoneLeftPending(): void
    {
        $inspection = $this->inspection(InspectionKind::Checkin, [
            $this->entry('kitchen', EntryState::Ok, false, []),
            $this->entry('outdoor', EntryState::Pending, false, []),
        ]);

        $blocking = $inspection->blocking();

        self::assertCount(1, $blocking);
        self::assertSame('outdoor', $blocking[0]->zone->code);
        self::assertFalse($inspection->isComplete());
    }

    public function testADepartureIsBlockedByAMissingPhotoOnARequiredZone(): void
    {
        $inspection = $this->inspection(InspectionKind::Checkout, [
            // Conforme, mais sans la photo que la zone exige.
            $this->entry('kitchen', EntryState::Ok, true, []),
            $this->entry('outdoor', EntryState::Ok, false, []),
        ]);

        $blocking = $inspection->blocking();

        self::assertCount(1, $blocking);
        self::assertSame('kitchen', $blocking[0]->zone->code);
        self::assertSame(['done' => 1, 'total' => 2], $inspection->progress());
    }

    public function testADepartureIsCompleteOnceTheRequiredPhotosArePresent(): void
    {
        $inspection = $this->inspection(InspectionKind::Checkout, [
            $this->entry('kitchen', EntryState::Ok, true, [12]),
            // Photo facultative : son absence ne bloque rien.
            $this->entry('outdoor', EntryState::Anomaly, false, []),
        ]);

        self::assertSame([], $inspection->blocking());
        self::assertTrue($inspection->isComplete());
        self::assertCount(1, $inspection->anomalies());
    }

    public function testOnlyTheDepartureDemandsPhotos(): void
    {
        self::assertFalse(InspectionKind::Checkin->requiresPhotos());
        self::assertTrue(InspectionKind::Checkout->requiresPhotos());
    }

    public function testEachKindAdvancesItsOwnSubStatus(): void
    {
        self::assertSame('checkin_status', InspectionKind::Checkin->bookingColumn());
        self::assertSame('checkout_status', InspectionKind::Checkout->bookingColumn());
    }

    public function testAnUnknownStateFallsBackToPendingRatherThanFailing(): void
    {
        // La valeur vient d'un formulaire : elle ne peut pas être crue, et une
        // valeur inconnue ne doit jamais valoir « conforme ».
        self::assertSame(EntryState::Pending, EntryState::fromString('excellent'));
        self::assertSame(EntryState::Pending, EntryState::fromString(''));
        self::assertSame(EntryState::Ok, EntryState::fromString('  OK '));
    }

    // --- Cycle de vie d'un incident -------------------------------------------------

    /**
     * @return list<array{IncidentStatus, IncidentStatus, bool}>
     */
    public static function transitions(): array
    {
        return [
            [IncidentStatus::Reported, IncidentStatus::Acknowledged, true],
            [IncidentStatus::Reported, IncidentStatus::Resolved, true],
            [IncidentStatus::Reported, IncidentStatus::Reported, false],
            [IncidentStatus::Acknowledged, IncidentStatus::Resolved, true],
            [IncidentStatus::Acknowledged, IncidentStatus::Reported, false],
            // Rouvrir, oui ; revenir à « jamais lu », non.
            [IncidentStatus::Resolved, IncidentStatus::Acknowledged, true],
            [IncidentStatus::Resolved, IncidentStatus::Reported, false],
        ];
    }

    #[DataProvider('transitions')]
    public function testIncidentTransitionsAreExplicit(
        IncidentStatus $from,
        IncidentStatus $to,
        bool $allowed,
    ): void {
        self::assertSame($allowed, $from->canMoveTo($to));
    }

    public function testOnlyUrgentIsUrgent(): void
    {
        self::assertTrue(IncidentSeverity::Urgent->isUrgent());
        self::assertFalse(IncidentSeverity::Normal->isUrgent());
        self::assertFalse(IncidentSeverity::Low->isUrgent());
        self::assertSame(IncidentSeverity::Normal, IncidentSeverity::fromString('inconnu'));
    }

    public function testSeverityWeightSortsTheMostUrgentFirst(): void
    {
        $severities = [IncidentSeverity::Low, IncidentSeverity::Urgent, IncidentSeverity::Normal];
        usort($severities, static fn (IncidentSeverity $a, IncidentSeverity $b): int => $a->weight() <=> $b->weight());

        self::assertSame(
            [IncidentSeverity::Urgent, IncidentSeverity::Normal, IncidentSeverity::Low],
            $severities
        );
    }

    // --- Fabriques ------------------------------------------------------------------

    /**
     * @param list<InspectionEntry> $entries
     */
    private function inspection(InspectionKind $kind, array $entries): Inspection
    {
        return new Inspection(
            1,
            7,
            $kind,
            InspectionStatus::Open,
            'fr',
            '2026-07-04 15:00:00',
            null,
            null,
            '',
            $entries,
        );
    }

    /**
     * @param list<int> $photoIds
     */
    private function entry(string $code, EntryState $state, bool $photoRequired, array $photoIds): InspectionEntry
    {
        return new InspectionEntry(
            crc32($code),
            1,
            new Zone(crc32($code), $code, 10, $photoRequired, true, '', '', ''),
            $state,
            '',
            $photoIds,
            '2026-07-04 15:00:00',
        );
    }
}
