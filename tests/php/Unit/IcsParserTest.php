<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Calendar\IcsParser;

/**
 * Lecture des flux iCalendar importés (SPECIFICATIONS.md §52).
 *
 * Les flux réels sont irréguliers : lignes repliées, dates sans heure, UID
 * absents, corps qui n'est pas un calendrier. Le lecteur doit rendre quelque
 * chose d'exact ou rien, jamais quelque chose d'approximatif : une nuit
 * bloquée à tort se vend deux fois.
 */
final class IcsParserTest extends TestCase
{
    private function parser(): IcsParser
    {
        return new IcsParser();
    }

    private function feed(string $body): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . $body . "END:VCALENDAR\r\n";
    }

    public function testReadsASimpleEvent(): void
    {
        $events = $this->parser()->parse($this->feed(
            "BEGIN:VEVENT\r\nUID:abc-1\r\nDTSTART;VALUE=DATE:20260701\r\n"
            . "DTEND;VALUE=DATE:20260705\r\nSUMMARY:Reserved\r\nEND:VEVENT\r\n"
        ));

        self::assertCount(1, $events);
        self::assertSame('abc-1', $events[0]['uid']);
        self::assertSame('2026-07-01', $events[0]['start']);
        self::assertSame('2026-07-05', $events[0]['end']);
        self::assertSame('Reserved', $events[0]['summary']);
    }

    public function testAcceptsLineFeedsWithoutCarriageReturn(): void
    {
        $events = $this->parser()->parse(
            "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:u\nDTSTART:20260301\nDTEND:20260303\nEND:VEVENT\nEND:VCALENDAR\n"
        );

        self::assertCount(1, $events);
        self::assertSame('2026-03-03', $events[0]['end']);
    }

    public function testUnfoldsContinuationLines(): void
    {
        $events = $this->parser()->parse($this->feed(
            "BEGIN:VEVENT\r\nUID:u\r\nDTSTART:20260701\r\nDTEND:20260702\r\n"
            . "SUMMARY:Semaine de\r\n  juillet\r\nEND:VEVENT\r\n"
        ));

        self::assertSame('Semaine de juillet', $events[0]['summary']);
    }

    public function testFullTimestampsAreReducedToTheDay(): void
    {
        $events = $this->parser()->parse($this->feed(
            "BEGIN:VEVENT\r\nUID:u\r\nDTSTART:20260701T140000Z\r\nDTEND:20260703T100000Z\r\nEND:VEVENT\r\n"
        ));

        self::assertSame('2026-07-01', $events[0]['start']);
        self::assertSame('2026-07-03', $events[0]['end']);
    }

    public function testAMissingEndMeansASingleNight(): void
    {
        $events = $this->parser()->parse($this->feed(
            "BEGIN:VEVENT\r\nUID:u\r\nDTSTART;VALUE=DATE:20260214\r\nEND:VEVENT\r\n"
        ));

        self::assertSame('2026-02-14', $events[0]['start']);
        self::assertSame('2026-02-15', $events[0]['end']);
    }

    public function testAMissingUidIsDerivedAndStable(): void
    {
        $body = $this->feed(
            "BEGIN:VEVENT\r\nDTSTART:20260701\r\nDTEND:20260705\r\nSUMMARY:Busy\r\nEND:VEVENT\r\n"
        );

        $first = $this->parser()->parse($body);
        $second = $this->parser()->parse($body);

        self::assertStringStartsWith('sha-', $first[0]['uid']);
        self::assertSame($first[0]['uid'], $second[0]['uid']);
    }

    public function testDerivedUidsDifferBetweenEvents(): void
    {
        $events = $this->parser()->parse($this->feed(
            "BEGIN:VEVENT\r\nDTSTART:20260701\r\nDTEND:20260705\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART:20260801\r\nDTEND:20260805\r\nEND:VEVENT\r\n"
        ));

        self::assertCount(2, $events);
        self::assertNotSame($events[0]['uid'], $events[1]['uid']);
    }

    public function testEscapedTextIsDecoded(): void
    {
        $events = $this->parser()->parse($this->feed(
            "BEGIN:VEVENT\r\nUID:u\r\nDTSTART:20260701\r\nDTEND:20260702\r\n"
            . "SUMMARY:Dupont\\, Marie\\nArrivée tardive\r\nEND:VEVENT\r\n"
        ));

        self::assertSame("Dupont, Marie\nArrivée tardive", $events[0]['summary']);
    }

    public function testAnEndBeforeTheStartIsRefused(): void
    {
        $events = $this->parser()->parse($this->feed(
            "BEGIN:VEVENT\r\nUID:u\r\nDTSTART:20260705\r\nDTEND:20260701\r\nEND:VEVENT\r\n"
        ));

        self::assertSame([], $events);
    }

    public function testAnImpossibleDateIsRefused(): void
    {
        $events = $this->parser()->parse($this->feed(
            "BEGIN:VEVENT\r\nUID:u\r\nDTSTART:20260230\r\nDTEND:20260305\r\nEND:VEVENT\r\n"
        ));

        self::assertSame([], $events);
    }

    public function testHtmlIsNotACalendar(): void
    {
        self::assertSame([], $this->parser()->parse('<html><body>Not found</body></html>'));
    }

    public function testAnEmptyBodyYieldsNothing(): void
    {
        self::assertSame([], $this->parser()->parse(''));
    }

    public function testAnOversizedBodyIsRefusedWithoutParsing(): void
    {
        $body = $this->feed(
            "BEGIN:VEVENT\r\nUID:u\r\nDTSTART:20260701\r\nDTEND:20260702\r\nEND:VEVENT\r\n"
            . str_repeat('X', IcsParser::MAX_BYTES)
        );

        self::assertSame([], $this->parser()->parse($body));
    }

    public function testTheNumberOfEventsIsCapped(): void
    {
        $body = '';
        for ($index = 0; $index < IcsParser::MAX_EVENTS + 50; $index++) {
            $body .= "BEGIN:VEVENT\r\nUID:u-{$index}\r\nDTSTART:20260701\r\nDTEND:20260702\r\nEND:VEVENT\r\n";
        }

        self::assertCount(IcsParser::MAX_EVENTS, $this->parser()->parse($this->feed($body)));
    }

    public function testPropertiesOutsideAnEventAreIgnored(): void
    {
        $events = $this->parser()->parse($this->feed(
            "DTSTART:20260101\r\nSUMMARY:Entête\r\n"
            . "BEGIN:VEVENT\r\nUID:u\r\nDTSTART:20260701\r\nDTEND:20260702\r\nEND:VEVENT\r\n"
        ));

        self::assertCount(1, $events);
        self::assertSame('', $events[0]['summary']);
    }

    public function testAnUnterminatedEventIsDropped(): void
    {
        $events = $this->parser()->parse(
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:u\r\nDTSTART:20260701\r\nDTEND:20260702\r\n"
        );

        self::assertSame([], $events);
    }

    public function testLowercaseKeywordsAreAccepted(): void
    {
        $events = $this->parser()->parse(
            "begin:vcalendar\r\nbegin:vevent\r\nuid:u\r\ndtstart:20260701\r\ndtend:20260702\r\n"
            . "end:vevent\r\nend:vcalendar\r\n"
        );

        self::assertCount(1, $events);
        self::assertSame('u', $events[0]['uid']);
    }
}
