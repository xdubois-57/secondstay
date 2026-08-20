<?php

declare(strict_types=1);

namespace SecondStay\Support;

/**
 * Correction d'erreur Reed-Solomon sur GF(256), telle que la norme QR
 * l'exige.
 *
 * Le polynôme générateur du corps est 0x11D.
 */
final class ReedSolomon
{
    /** @var list<int>|null */
    private static ?array $exp = null;

    /** @var array<int, int>|null table inverse, indexée par élément du corps */
    private static ?array $log = null;

    /**
     * @param list<int> $data
     *
     * @return list<int> codets de correction
     */
    public static function encode(array $data, int $count): array
    {
        self::initialise();

        $generator = self::generator($count);
        $remainder = array_fill(0, $count, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ ($remainder[0] ?? 0);

            $next = [];
            for ($index = 0; $index < $count; $index++) {
                // Le reste est décalé d'un cran à chaque octet consommé.
                $shifted = $remainder[$index + 1] ?? 0;
                $next[] = $shifted ^ self::multiply($generator[$index] ?? 0, $factor);
            }

            $remainder = $next;
        }

        return $remainder;
    }

    public static function multiply(int $a, int $b): int
    {
        self::initialise();

        if ($a === 0 || $b === 0) {
            return 0;
        }

        /** @var array<int, int> $log */
        $log = self::$log;
        /** @var list<int> $exp */
        $exp = self::$exp;

        return $exp[($log[$a] + $log[$b]) % 255];
    }

    /**
     * @return list<int>
     */
    private static function generator(int $degree): array
    {
        $polynomial = [1];

        for ($index = 0; $index < $degree; $index++) {
            $root = self::$exp[$index] ?? 0;
            $length = count($polynomial);

            // g(x) = produit des (x - alpha^i). Les coefficients sont rangés
            // du degré le plus fort au plus faible : multiplier par
            // (x + alpha^i) revient donc à additionner le polynôme décalé
            // d'un rang et le polynôme multiplié par alpha^i.
            $next = [];
            for ($position = 0; $position <= $length; $position++) {
                $next[] = ($polynomial[$position] ?? 0)
                    ^ self::multiply($polynomial[$position - 1] ?? 0, $root);
            }

            $polynomial = $next;
        }

        // Le coefficient de tête vaut toujours 1 : il n'entre pas dans le
        // calcul du reste.
        return array_slice($polynomial, 1);
    }

    private static function initialise(): void
    {
        if (self::$exp !== null) {
            return;
        }

        $exp = [];
        $log = array_fill(0, 256, 0);

        $value = 1;
        for ($index = 0; $index < 255; $index++) {
            $exp[] = $value;
            $log[$value] = $index;

            $value <<= 1;
            if ($value >= 256) {
                $value ^= 0x11D;
            }
        }
        // alpha^255 = alpha^0 : la table boucle, ce qui évite un modulo de
        // plus à chaque multiplication.
        $exp[] = $exp[0];

        self::$exp = $exp;
        self::$log = $log;
    }
}
