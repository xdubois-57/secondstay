<?php

declare(strict_types=1);

namespace SecondStay\Support;

/**
 * Conversion d'un montant saisi en centimes entiers.
 *
 * La saisie est tolérante — virgule ou point décimal, espaces fines — mais le
 * stockage reste canonique : un entier de centimes, indépendant de la locale
 * (I18N.md §7).
 */
final class Money
{
    /**
     * @return int|null centimes, ou null si la saisie n'est pas un montant
     */
    public static function parse(string $input): ?int
    {
        $normalised = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($input));

        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $normalised) !== 1) {
            return null;
        }

        return (int) round((float) $normalised * 100);
    }
}
