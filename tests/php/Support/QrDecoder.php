<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use RuntimeException;

/**
 * Décodeur QR indépendant, écrit uniquement pour les tests.
 *
 * Il ne partage aucune ligne de code avec l'encodeur : il relit la matrice
 * comme le ferait un lecteur, en repartant de la spécification (informations
 * de format, démasquage, parcours en zigzag, désentrelacement, mode octet).
 * Un aller-retour réussi prouve donc que la matrice produite est réellement
 * lisible, et pas seulement bien formée.
 *
 * Il ne corrige pas les erreurs : il n'y en a aucune sur une matrice
 * fraîchement encodée, et une divergence doit faire échouer le test.
 */
final class QrDecoder
{
    /**
     * Découpage en blocs, niveau M — tel que publié dans la norme.
     *
     * @var array<int, array{ec: int, blocks: list<int>}>
     */
    private const BLOCKS = [
        1 => ['ec' => 10, 'blocks' => [16]],
        2 => ['ec' => 16, 'blocks' => [28]],
        3 => ['ec' => 26, 'blocks' => [44]],
        4 => ['ec' => 18, 'blocks' => [32, 32]],
        5 => ['ec' => 24, 'blocks' => [43, 43]],
        6 => ['ec' => 16, 'blocks' => [27, 27, 27, 27]],
        7 => ['ec' => 18, 'blocks' => [31, 31, 31, 31]],
        8 => ['ec' => 22, 'blocks' => [38, 38, 39, 39]],
        9 => ['ec' => 22, 'blocks' => [36, 36, 36, 37, 37]],
        10 => ['ec' => 26, 'blocks' => [43, 43, 43, 43, 44]],
        11 => ['ec' => 30, 'blocks' => [50, 51, 51, 51, 51]],
        12 => ['ec' => 22, 'blocks' => [36, 36, 36, 36, 36, 36, 37, 37]],
        13 => ['ec' => 22, 'blocks' => [37, 37, 37, 37, 37, 37, 37, 37, 38]],
        14 => ['ec' => 24, 'blocks' => [40, 40, 40, 40, 41, 41, 41, 41, 41]],
        15 => ['ec' => 24, 'blocks' => [41, 41, 41, 41, 41, 42, 42, 42, 42, 42]],
        16 => ['ec' => 28, 'blocks' => [45, 45, 45, 45, 45, 45, 45, 46, 46, 46]],
        17 => ['ec' => 28, 'blocks' => [46, 46, 46, 46, 46, 46, 46, 46, 46, 46, 47]],
        18 => ['ec' => 26, 'blocks' => [43, 43, 43, 43, 43, 43, 43, 43, 43, 44, 44, 44, 44]],
        19 => ['ec' => 26, 'blocks' => [44, 44, 44, 45, 45, 45, 45, 45, 45, 45, 45, 45, 45, 45]],
        20 => ['ec' => 26, 'blocks' => [41, 41, 41, 42, 42, 42, 42, 42, 42, 42, 42, 42, 42, 42, 42, 42]],
    ];

    /** @var list<list<int>> */
    private array $modules;

    private int $size;

    private int $version;

    /** @var array<int, bool> carte aplatie, indexée par `y * size + x` */
    private array $functional;

    /**
     * @param list<list<int>> $modules
     */
    public function __construct(array $modules)
    {
        $this->modules = $modules;
        $this->size = count($modules);

        if (($this->size - 17) % 4 !== 0) {
            throw new RuntimeException('Taille de matrice impossible : ' . $this->size);
        }

        $this->version = intdiv($this->size - 17, 4);
        $this->functional = $this->mapFunctionalModules();
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Version relue dans les modules eux-mêmes, versions 7 et au-delà.
     */
    public function declaredVersion(): ?int
    {
        if ($this->version < 7) {
            return null;
        }

        $bits = 0;
        for ($index = 0; $index < 18; $index++) {
            $x = intdiv($index, 3);
            $y = $this->size - 11 + ($index % 3);
            $bits |= $this->modules[$y][$x] << $index;

            if ($this->modules[$y][$x] !== $this->modules[$x][$y]) {
                throw new RuntimeException('Les deux copies de la version divergent.');
            }
        }

        return $bits >> 12;
    }

    public function mask(): int
    {
        $bits = 0;
        foreach ($this->formatPositions() as $index => [$x, $y]) {
            $bits |= $this->modules[$y][$x] << $index;
        }

        $value = ($bits ^ 0x5412) >> 10;
        $level = $value >> 3;

        if ($level !== 0) {
            throw new RuntimeException('Niveau de correction inattendu : ' . $level);
        }

        return $value & 0x07;
    }

    /**
     * Vérifie que la seconde copie des informations de format est identique.
     */
    public function assertFormatCopiesAgree(): void
    {
        foreach ($this->formatPositions() as $index => [$x, $y]) {
            [$mirrorX, $mirrorY] = $index < 8
                ? [$this->size - 1 - $index, 8]
                : [8, $this->size - 15 + $index];

            if ($this->modules[$y][$x] !== $this->modules[$mirrorY][$mirrorX]) {
                throw new RuntimeException('Les copies du format divergent au bit ' . $index);
            }
        }
    }

    public function decode(): string
    {
        $mask = $this->mask();
        $codewords = $this->readCodewords($mask);
        $data = $this->deinterleave($codewords);

        $bits = '';
        foreach ($data as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $mode = bindec(substr($bits, 0, 4));
        if ($mode !== 0b0100) {
            throw new RuntimeException('Mode inattendu : ' . $mode);
        }

        $countBits = $this->version <= 9 ? 8 : 16;
        $length = (int) bindec(substr($bits, 4, $countBits));
        $offset = 4 + $countBits;

        $text = '';
        for ($index = 0; $index < $length; $index++) {
            $text .= chr((int) bindec(substr($bits, $offset + $index * 8, 8)));
        }

        return $text;
    }

    /**
     * Positions des quinze bits de format, première copie.
     *
     * @return list<array{int, int}>
     */
    private function formatPositions(): array
    {
        $positions = [];
        for ($index = 0; $index <= 5; $index++) {
            $positions[] = [8, $index];
        }
        $positions[] = [8, 7];
        $positions[] = [8, 8];
        $positions[] = [7, 8];
        for ($index = 9; $index < 15; $index++) {
            $positions[] = [14 - $index, 8];
        }

        return $positions;
    }

    /**
     * @return list<int>
     */
    private function readCodewords(int $mask): array
    {
        $bits = '';
        $upward = true;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($step = 0; $step < $this->size; $step++) {
                $y = $upward ? $this->size - 1 - $step : $step;

                foreach ([$right, $right - 1] as $x) {
                    if ($this->functional[$y * $this->size + $x] ?? false) {
                        continue;
                    }

                    $value = $this->modules[$y][$x];
                    if ($this->maskCondition($mask, $x, $y)) {
                        $value ^= 1;
                    }

                    $bits .= (string) $value;
                }
            }

            $upward = !$upward;
        }

        $codewords = [];
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $codewords[] = (int) bindec($byte);
        }

        return $codewords;
    }

    /**
     * Reconstitue les blocs de données à partir du flux entrelacé.
     *
     * @param list<int> $codewords
     *
     * @return list<int>
     */
    private function deinterleave(array $codewords): array
    {
        $spec = self::BLOCKS[$this->version];
        $lengths = $spec['blocks'];
        $longest = max($lengths);

        $blocks = array_fill(0, count($lengths), []);
        $position = 0;

        for ($column = 0; $column < $longest; $column++) {
            foreach ($lengths as $index => $length) {
                if ($column < $length) {
                    $blocks[$index][] = $codewords[$position];
                    $position++;
                }
            }
        }

        $data = [];
        foreach ($blocks as $block) {
            foreach ($block as $byte) {
                $data[] = $byte;
            }
        }

        return $data;
    }

    private function maskCondition(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
            6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            7 => (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0,
            default => throw new RuntimeException('Masque inconnu : ' . $mask),
        };
    }

    /**
     * Carte des modules de fonction, redéduite de la spécification.
     *
     * @return array<int, bool>
     */
    private function mapFunctionalModules(): array
    {
        $map = array_fill(0, $this->size * $this->size, false);

        $mark = function (int $x, int $y) use (&$map): void {
            if ($x >= 0 && $y >= 0 && $x < $this->size && $y < $this->size) {
                $map[$y * $this->size + $x] = true;
            }
        };

        // Détecteurs de position, séparateurs et zones de format.
        foreach ([[0, 0], [$this->size - 7, 0], [0, $this->size - 7]] as [$originX, $originY]) {
            for ($x = -1; $x <= 7; $x++) {
                for ($y = -1; $y <= 7; $y++) {
                    $mark($originX + $x, $originY + $y);
                }
            }
        }

        // Synchronisation.
        for ($index = 0; $index < $this->size; $index++) {
            $mark($index, 6);
            $mark(6, $index);
        }

        // Alignement.
        $centres = $this->alignmentCentres();
        foreach ($centres as $x) {
            foreach ($centres as $y) {
                if (($x < 8 && $y < 8) || ($x < 8 && $y > $this->size - 9) || ($x > $this->size - 9 && $y < 8)) {
                    continue;
                }
                for ($dx = -2; $dx <= 2; $dx++) {
                    for ($dy = -2; $dy <= 2; $dy++) {
                        $mark($x + $dx, $y + $dy);
                    }
                }
            }
        }

        // Informations de format, deuxième copie, et module toujours noir.
        for ($index = 0; $index < 8; $index++) {
            $mark($this->size - 1 - $index, 8);
            $mark(8, $this->size - 1 - $index);
        }

        // Informations de version.
        if ($this->version >= 7) {
            for ($x = 0; $x < 6; $x++) {
                for ($y = 0; $y < 3; $y++) {
                    $mark($x, $this->size - 11 + $y);
                    $mark($this->size - 11 + $y, $x);
                }
            }
        }

        return $map;
    }

    /**
     * Centres des motifs d'alignement, calculés d'après la norme.
     *
     * @return list<int>
     */
    private function alignmentCentres(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $count = intdiv($this->version, 7) + 2;
        $step = $this->version === 32
            ? 26
            : (int) (ceil(($this->version * 4 + 4) / ($count * 2 - 2)) * 2);

        $centres = [6];
        for ($position = $this->size - 7; count($centres) < $count; $position -= $step) {
            array_splice($centres, 1, 0, [$position]);
        }

        sort($centres);

        return $centres;
    }
}
