<?php

declare(strict_types=1);

namespace SecondStay\I18n;

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
