<?php

declare(strict_types=1);

namespace SecondStay\Pdf;

use SecondStay\Support\Ascii;

/**
 * Conversion UTF-8 → WinAnsiEncoding, le codage des polices standard PDF.
 *
 * Ce codage couvre l'intégralité des lettres du français, du néerlandais, de
 * l'allemand et de l'anglais, ainsi que l'euro, les guillemets français et
 * l'apostrophe typographique. Les rares caractères hors table sont
 * translittérés plutôt que perdus : un contrat où « œ » deviendrait un carré
 * vide serait pire qu'un contrat où il devient « oe ».
 */
final class WinAnsi
{
    /**
     * Points de code au-delà de Latin-1 qui ont malgré tout une place dans
     * WinAnsi, entre 0x80 et 0x9F.
     *
     * @var array<int, int>
     */
    private const HIGH = [
        0x20AC => 0x80, // €
        0x201A => 0x82,
        0x0192 => 0x83,
        0x201E => 0x84,
        0x2026 => 0x85, // …
        0x2020 => 0x86,
        0x2021 => 0x87,
        0x02C6 => 0x88,
        0x2030 => 0x89,
        0x0160 => 0x8A,
        0x2039 => 0x8B,
        0x0152 => 0x8C, // Œ
        0x017D => 0x8E,
        0x2018 => 0x91,
        0x2019 => 0x92, // ’
        0x201C => 0x93,
        0x201D => 0x94,
        0x2022 => 0x95,
        0x2013 => 0x96, // –
        0x2014 => 0x97, // —
        0x02DC => 0x98,
        0x2122 => 0x99,
        0x0161 => 0x9A,
        0x203A => 0x9B,
        0x0153 => 0x9C, // œ
        0x017E => 0x9E,
        0x0178 => 0x9F,
    ];

    /**
     * Translittérations pour ce qui n'a pas de place du tout.
     *
     * @var array<int, string>
     */
    private const FALLBACK = [
        0x2212 => '-',
        0x00A0 => ' ',
        0x202F => ' ',
        0x2009 => ' ',
        0x2011 => '-',
        0x2500 => '-',
        0x2192 => '->',
        0x2190 => '<-',
    ];

    public static function encode(string $text): string
    {
        $out = '';

        foreach (self::codePoints($text) as $code) {
            if ($code === 0x0A || $code === 0x0D) {
                $out .= "\n";
                continue;
            }

            if ($code < 0x20) {
                continue;
            }

            if ($code < 0x80 || ($code >= 0xA0 && $code <= 0xFF)) {
                $out .= chr($code);
                continue;
            }

            if (isset(self::HIGH[$code])) {
                $out .= chr(self::HIGH[$code]);
                continue;
            }

            if (isset(self::FALLBACK[$code])) {
                $out .= self::FALLBACK[$code];
                continue;
            }

            $out .= self::transliterate($code);
        }

        return $out;
    }

    /**
     * Échappement des caractères qui ont un sens dans une chaîne PDF.
     */
    public static function escape(string $encoded): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $encoded);
    }

    /**
     * @return list<int>
     */
    private static function codePoints(string $text): array
    {
        $points = [];

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $code = mb_ord($character, 'UTF-8');
            if ($code !== false) {
                $points[] = $code;
            }
        }

        return $points;
    }

    /**
     * Dernier recours pour un caractère absent de WinAnsi : sa forme ASCII
     * si la table en connaît une, sinon un point d'interrogation. Perdre un
     * caractère est acceptable ; produire un PDF illisible ne l'est pas.
     */
    private static function transliterate(int $code): string
    {
        $character = mb_chr($code, 'UTF-8');
        if ($character === false) {
            return '?';
        }

        $ascii = Ascii::fold($character);

        return $ascii === '' ? '?' : $ascii;
    }
}
