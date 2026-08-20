<?php

declare(strict_types=1);

namespace SecondStay\Calendar;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Génération d'un flux iCalendar (RFC 5545).
 *
 * Écrit ici parce qu'un flux ICS n'est pas seulement « du texte avec des
 * deux-points » : le pliage des lignes à 75 octets, l'échappement des
 * séparateurs et la convention de date de fin exclusive sont exactement ce
 * qu'un agenda applique à la lettre. Un flux presque correct s'affiche décalé
 * d'un jour, ou pas du tout.
 */
final class IcsCalendar
{
    public const PRODUCT_ID = '-//SecondStay//Calendrier//FR';

    /** Longueur maximale d'une ligne, en octets, avant pliage. */
    private const LINE_OCTETS = 75;

    /** @var list<string> */
    private array $lines = [];

    public function __construct(
        private readonly string $name,
        private readonly string $description = '',
    ) {
    }

    /**
     * Ajoute un séjour, en journées entières.
     *
     * La date de fin d'un événement « toute la journée » est **exclusive** :
     * un départ le 11 juillet se déclare `DTEND;VALUE=DATE:20260711`, faute de
     * quoi l'agenda occuperait une nuit de trop et le logement paraîtrait
     * indisponible le jour d'une arrivée suivante.
     *
     * @param array<string, string> $extra propriétés supplémentaires
     */
    public function addAllDayEvent(
        string $uid,
        DateTimeInterface $start,
        DateTimeInterface $endExclusive,
        string $summary,
        string $description = '',
        string $location = '',
        array $extra = [],
    ): void {
        $this->lines[] = 'BEGIN:VEVENT';
        $this->lines[] = 'UID:' . self::escape($uid);
        $this->lines[] = 'DTSTAMP:' . self::timestamp($start);
        $this->lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
        $this->lines[] = 'DTEND;VALUE=DATE:' . $endExclusive->format('Ymd');
        $this->lines[] = 'SUMMARY:' . self::escape($summary);

        if ($description !== '') {
            $this->lines[] = 'DESCRIPTION:' . self::escape($description);
        }

        if ($location !== '') {
            $this->lines[] = 'LOCATION:' . self::escape($location);
        }

        foreach ($extra as $property => $value) {
            $this->lines[] = strtoupper($property) . ':' . self::escape($value);
        }

        $this->lines[] = 'TRANSP:OPAQUE';
        $this->lines[] = 'END:VEVENT';
    }

    /**
     * Rendu complet, lignes pliées et terminées par CRLF.
     */
    public function render(): string
    {
        $header = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODUCT_ID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape($this->name),
        ];

        if ($this->description !== '') {
            $header[] = 'X-WR-CALDESC:' . self::escape($this->description);
        }

        $body = array_merge($header, $this->lines, ['END:VCALENDAR']);

        $out = '';
        foreach ($body as $line) {
            $out .= self::fold($line) . "\r\n";
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * Échappement des caractères qui structurent une propriété (RFC 5545 §3.3.11).
     */
    public static function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ',' ],
            ['\\\\', '\\n', '\\n', '\\n', '\;', '\\,'],
            $value
        );
    }

    /**
     * Pliage à 75 octets, la suite préfixée d'une espace.
     *
     * Le découpage se fait sur les octets, jamais sur les caractères : couper
     * au milieu d'un caractère UTF-8 produirait un flux illisible.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::LINE_OCTETS) {
            return $line;
        }

        $folded = '';
        $current = '';

        foreach (self::characters($line) as $character) {
            // La ligne de continuation commence par une espace, qui compte
            // dans sa longueur : le budget est donc réduit d'un octet.
            $limit = $folded === '' ? self::LINE_OCTETS : self::LINE_OCTETS - 1;

            if (strlen($current) + strlen($character) > $limit) {
                $folded .= ($folded === '' ? '' : "\r\n ") . $current;
                $current = '';
            }

            $current .= $character;
        }

        if ($current !== '') {
            $folded .= ($folded === '' ? '' : "\r\n ") . $current;
        }

        return $folded;
    }

    /**
     * Horodatage UTC, tel que la norme l'exige pour `DTSTAMP`.
     */
    public static function timestamp(DateTimeInterface $reference): string
    {
        return (new DateTimeImmutable('@' . $reference->getTimestamp()))->format('Ymd\THis\Z');
    }

    /**
     * @return list<string>
     */
    private static function characters(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        // Une chaîne qui n'est pas de l'UTF-8 valide est découpée octet par
        // octet plutôt que perdue.
        return $characters === false ? str_split($value) : $characters;
    }
}
