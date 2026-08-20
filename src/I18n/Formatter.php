<?php

declare(strict_types=1);

namespace SecondStay\I18n;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Formatage localise. Le stockage metier reste canonique :
 * dates ISO, montants en centimes entiers, devise EUR.
 */
final class Formatter
{
    public function __construct(
        private readonly string $locale = Locales::FALLBACK,
        private readonly string $timezone = 'Europe/Paris',
        private readonly string $currency = 'EUR',
    ) {
    }

    public function withLocale(string $locale): self
    {
        return new self($locale, $this->timezone, $this->currency);
    }

    public function money(int $cents): string
    {
        $formatter = new NumberFormatter(Locales::icu($this->locale), NumberFormatter::CURRENCY);
        $result = $formatter->formatCurrency($cents / 100, $this->currency);

        if ($result === false) {
            return number_format($cents / 100, 2, ',', ' ') . ' ' . $this->currency;
        }

        // L'espace insecable etroit d'ICU casse certaines comparaisons de test ;
        // on normalise vers l'espace insecable classique.
        return str_replace("\u{202F}", "\u{00A0}", $result);
    }

    public function number(float $value, int $decimals = 2): string
    {
        $formatter = new NumberFormatter(Locales::icu($this->locale), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $result = $formatter->format($value);

        return $result === false ? (string) $value : $result;
    }

    public function date(DateTimeInterface $date, string $width = 'medium'): string
    {
        return $this->intlFormat($date, $this->widthConstant($width), IntlDateFormatter::NONE);
    }

    public function dateTime(DateTimeInterface $date, string $width = 'medium'): string
    {
        return $this->intlFormat($date, $this->widthConstant($width), IntlDateFormatter::SHORT);
    }

    public function time(DateTimeInterface $date): string
    {
        return $this->intlFormat($date, IntlDateFormatter::NONE, IntlDateFormatter::SHORT);
    }

    /**
     * Mois et année en toutes lettres, pour l'en-tête d'un calendrier.
     */
    public function monthName(DateTimeInterface $date): string
    {
        return $this->pattern($date, 'LLLL yyyy');
    }

    /**
     * Jour et mois, sans l'année : suffisant pour un séjour dans l'année en
     * cours et plus lisible dans un résumé.
     */
    public function dayAndMonth(DateTimeInterface $date): string
    {
        return $this->pattern($date, 'd MMM');
    }

    /**
     * Noms abrégés des sept jours, du lundi au dimanche.
     *
     * @return list<string>
     */
    public function weekdayNames(): array
    {
        // 2024-01-01 est un lundi : la référence est indépendante de la locale.
        $monday = new DateTimeImmutable('2024-01-01', new DateTimeZone('UTC'));

        $names = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $names[] = $this->pattern($monday->modify('+' . $offset . ' days'), 'EEEEEE');
        }

        return $names;
    }

    private function pattern(DateTimeInterface $date, string $pattern): string
    {
        $formatter = new IntlDateFormatter(
            Locales::icu($this->locale),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            new DateTimeZone($this->timezone),
            null,
            $pattern
        );

        $result = $formatter->format($date);

        return $result === false ? $date->format('Y-m-d') : $result;
    }

    private function intlFormat(DateTimeInterface $date, int $dateType, int $timeType): string
    {
        $formatter = new IntlDateFormatter(
            Locales::icu($this->locale),
            $dateType,
            $timeType,
            new DateTimeZone($this->timezone)
        );

        $result = $formatter->format($date);

        return $result === false ? $date->format('Y-m-d H:i') : $result;
    }

    private function widthConstant(string $width): int
    {
        return match ($width) {
            'short' => IntlDateFormatter::SHORT,
            'long' => IntlDateFormatter::LONG,
            'full' => IntlDateFormatter::FULL,
            default => IntlDateFormatter::MEDIUM,
        };
    }

    public function locale(): string
    {
        return $this->locale;
    }
}
