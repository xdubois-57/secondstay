<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Calendar\IcsCalendar;
use SecondStay\Tests\Support\IcsReader;

/**
 * Flux iCalendar.
 *
 * Un flux presque correct s'affiche décalé d'un jour, ou pas du tout : chaque
 * cas relit donc le flux avec un lecteur indépendant plutôt que d'y chercher
 * une chaîne.
 */
final class IcsCalendarTest extends TestCase
{
    public function testTheFeedIsStructurallyValid(): void
    {
        $reader = new IcsReader($this->calendar()->render());

        self::assertSame(1, $reader->assertWellFormed());
        self::assertTrue($reader->usesCrLf(), 'Chaque ligne doit se terminer par CRLF.');
        self::assertSame(IcsCalendar::PRODUCT_ID, $reader->value('PRODID'));
        self::assertSame('Séjours — Maison des Pins', $reader->value('X-WR-CALNAME'));
    }

    /**
     * La convention est le cœur du sujet : `DTEND` est **exclusif**. Un départ
     * le 11 juillet doit libérer cette journée, sans quoi une arrivée le même
     * jour paraîtrait impossible.
     */
    public function testTheDepartureDayIsNotOccupied(): void
    {
        $event = (new IcsReader($this->calendar()->render()))->events()[0];

        self::assertSame('20260704', $event['DTSTART;VALUE=DATE']);
        self::assertSame('20260711', $event['DTEND;VALUE=DATE']);
    }

    public function testEveryEventCarriesTheRequiredProperties(): void
    {
        $event = (new IcsReader($this->calendar()->render()))->events()[0];

        foreach (['UID', 'DTSTAMP', 'DTSTART;VALUE=DATE', 'DTEND;VALUE=DATE', 'SUMMARY'] as $property) {
            self::assertArrayHasKey($property, $event);
        }

        self::assertSame('booking-1@secondstay.test', $event['UID']);
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $event['DTSTAMP']);
        self::assertSame('OPAQUE', $event['TRANSP']);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function specialValues(): array
    {
        return [
            ['Séjour ; important', 'Séjour ; important'],
            ['Nom, prénom', 'Nom, prénom'],
            ["Ligne\nsuivante", "Ligne\nsuivante"],
            ["Windows\r\nligne", "Windows\nligne"],
            ['Chemin C:\\Users', 'Chemin C:\\Users'],
            ['Tout : ; , \\ ensemble', 'Tout : ; , \\ ensemble'],
            ['Été à Chamonix — 350,00 €', 'Été à Chamonix — 350,00 €'],
        ];
    }

    #[DataProvider('specialValues')]
    public function testSeparatorsSurviveTheRoundTrip(string $summary, string $expected): void
    {
        $calendar = new IcsCalendar('Test');
        $calendar->addAllDayEvent(
            'uid-1',
            new DateTimeImmutable('2026-07-04'),
            new DateTimeImmutable('2026-07-11'),
            $summary,
            $summary,
        );

        $event = (new IcsReader($calendar->render()))->events()[0];

        self::assertSame($expected, $event['SUMMARY']);
        self::assertSame($expected, $event['DESCRIPTION']);
    }

    public function testLongValuesAreFoldedWithinTheLineLimit(): void
    {
        $calendar = new IcsCalendar('Test');
        $calendar->addAllDayEvent(
            'uid-long',
            new DateTimeImmutable('2026-07-04'),
            new DateTimeImmutable('2026-07-05'),
            str_repeat('Séjour très long ', 40),
        );

        $raw = $calendar->render();
        $reader = new IcsReader($raw);

        self::assertLessThanOrEqual(75, $reader->respectsLineLimit());
        self::assertSame(
            trim(str_repeat('Séjour très long ', 40)),
            trim($reader->events()[0]['SUMMARY']),
        );
    }

    /**
     * Le pliage se fait sur les octets : couper au milieu d'un caractère
     * accentué produirait un flux illisible.
     */
    public function testFoldingNeverSplitsAMultiByteCharacter(): void
    {
        $calendar = new IcsCalendar('Test');
        $calendar->addAllDayEvent(
            'uid-accents',
            new DateTimeImmutable('2026-07-04'),
            new DateTimeImmutable('2026-07-05'),
            str_repeat('é', 200),
        );

        $raw = $calendar->render();

        foreach (explode("\r\n", $raw) as $line) {
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'Chaque ligne reste de l’UTF-8 valide.');
        }

        self::assertSame(str_repeat('é', 200), (new IcsReader($raw))->events()[0]['SUMMARY']);
    }

    public function testAnEmptyCalendarIsStillValid(): void
    {
        $calendar = new IcsCalendar('Vide');
        $reader = new IcsReader($calendar->render());

        self::assertTrue($calendar->isEmpty());
        self::assertSame(0, $reader->assertWellFormed());
    }

    public function testSeveralEventsKeepTheirOrder(): void
    {
        $calendar = new IcsCalendar('Plusieurs');

        foreach ([['2026-07-04', '2026-07-11', 'Premier'], ['2026-08-01', '2026-08-08', 'Second']] as $index => $stay) {
            $calendar->addAllDayEvent(
                'uid-' . $index,
                new DateTimeImmutable($stay[0]),
                new DateTimeImmutable($stay[1]),
                $stay[2],
            );
        }

        $events = (new IcsReader($calendar->render()))->events();

        self::assertCount(2, $events);
        self::assertSame('Premier', $events[0]['SUMMARY']);
        self::assertSame('Second', $events[1]['SUMMARY']);
    }

    public function testOptionalPropertiesAreOmittedRatherThanEmpty(): void
    {
        $calendar = new IcsCalendar('Test');
        $calendar->addAllDayEvent(
            'uid-1',
            new DateTimeImmutable('2026-07-04'),
            new DateTimeImmutable('2026-07-05'),
            'Séjour',
        );

        $event = (new IcsReader($calendar->render()))->events()[0];

        self::assertArrayNotHasKey('DESCRIPTION', $event);
        self::assertArrayNotHasKey('LOCATION', $event);
    }

    public function testExtraPropertiesAreCarried(): void
    {
        $calendar = new IcsCalendar('Test');
        $calendar->addAllDayEvent(
            'uid-1',
            new DateTimeImmutable('2026-07-04'),
            new DateTimeImmutable('2026-07-05'),
            'Séjour',
            '',
            '',
            ['status' => 'CONFIRMED'],
        );

        self::assertSame('CONFIRMED', (new IcsReader($calendar->render()))->events()[0]['STATUS']);
    }

    private function calendar(): IcsCalendar
    {
        $calendar = new IcsCalendar('Séjours — Maison des Pins', 'Calendrier privé');
        $calendar->addAllDayEvent(
            'booking-1@secondstay.test',
            new DateTimeImmutable('2026-07-04'),
            new DateTimeImmutable('2026-07-11'),
            'Claire Dubois — WXYZ-3456',
            'Arrivée 16:00, départ 10:00',
            'Chamonix',
        );

        return $calendar;
    }
}
