<?php

declare(strict_types=1);

namespace SecondStay\Support;

use RuntimeException;

/**
 * Encodeur QR minimal, en mode octet.
 *
 * SecondStay produit un QR code EPC pour le virement SEPA : il faut donc
 * pouvoir en fabriquer un sans dépendance Composer supplémentaire ni service
 * externe — l'hébergement mutualisé visé n'a ni l'un ni l'autre, et une
 * référence de virement n'a rien à faire chez un tiers.
 *
 * Le champ couvert est volontairement étroit : mode octet, niveau de
 * correction M, versions 1 à 20. C'est très au-delà des ~200 octets d'un
 * message EPC.
 */
final class QrCode
{
    public const ERROR_CORRECTION = 'M';
    public const MAX_VERSION = 20;

    /**
     * Capacité en octets par version, en mode octet et niveau M.
     *
     * @var array<int, int>
     */
    private const CAPACITY = [
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
        11 => 251, 12 => 287, 13 => 331, 14 => 362, 15 => 412, 16 => 450, 17 => 504, 18 => 560,
        19 => 624, 20 => 666,
    ];

    /**
     * Nombre total de codets et découpage en blocs, niveau M.
     *
     * @var array<int, array{total: int, ec_per_block: int, group1_blocks: int,
     *     group1_data: int, group2_blocks: int, group2_data: int}>
     */
    private const BLOCKS = [
        1 => ['total' => 26, 'ec_per_block' => 10,
            'group1_blocks' => 1, 'group1_data' => 16, 'group2_blocks' => 0, 'group2_data' => 0],
        2 => ['total' => 44, 'ec_per_block' => 16,
            'group1_blocks' => 1, 'group1_data' => 28, 'group2_blocks' => 0, 'group2_data' => 0],
        3 => ['total' => 70, 'ec_per_block' => 26,
            'group1_blocks' => 1, 'group1_data' => 44, 'group2_blocks' => 0, 'group2_data' => 0],
        4 => ['total' => 100, 'ec_per_block' => 18,
            'group1_blocks' => 2, 'group1_data' => 32, 'group2_blocks' => 0, 'group2_data' => 0],
        5 => ['total' => 134, 'ec_per_block' => 24,
            'group1_blocks' => 2, 'group1_data' => 43, 'group2_blocks' => 0, 'group2_data' => 0],
        6 => ['total' => 172, 'ec_per_block' => 16,
            'group1_blocks' => 4, 'group1_data' => 27, 'group2_blocks' => 0, 'group2_data' => 0],
        7 => ['total' => 196, 'ec_per_block' => 18,
            'group1_blocks' => 4, 'group1_data' => 31, 'group2_blocks' => 0, 'group2_data' => 0],
        8 => ['total' => 242, 'ec_per_block' => 22,
            'group1_blocks' => 2, 'group1_data' => 38, 'group2_blocks' => 2, 'group2_data' => 39],
        9 => ['total' => 292, 'ec_per_block' => 22,
            'group1_blocks' => 3, 'group1_data' => 36, 'group2_blocks' => 2, 'group2_data' => 37],
        10 => ['total' => 346, 'ec_per_block' => 26,
            'group1_blocks' => 4, 'group1_data' => 43, 'group2_blocks' => 1, 'group2_data' => 44],
        11 => ['total' => 404, 'ec_per_block' => 30,
            'group1_blocks' => 1, 'group1_data' => 50, 'group2_blocks' => 4, 'group2_data' => 51],
        12 => ['total' => 466, 'ec_per_block' => 22,
            'group1_blocks' => 6, 'group1_data' => 36, 'group2_blocks' => 2, 'group2_data' => 37],
        13 => ['total' => 532, 'ec_per_block' => 22,
            'group1_blocks' => 8, 'group1_data' => 37, 'group2_blocks' => 1, 'group2_data' => 38],
        14 => ['total' => 581, 'ec_per_block' => 24,
            'group1_blocks' => 4, 'group1_data' => 40, 'group2_blocks' => 5, 'group2_data' => 41],
        15 => ['total' => 655, 'ec_per_block' => 24,
            'group1_blocks' => 5, 'group1_data' => 41, 'group2_blocks' => 5, 'group2_data' => 42],
        16 => ['total' => 733, 'ec_per_block' => 28,
            'group1_blocks' => 7, 'group1_data' => 45, 'group2_blocks' => 3, 'group2_data' => 46],
        17 => ['total' => 815, 'ec_per_block' => 28,
            'group1_blocks' => 10, 'group1_data' => 46, 'group2_blocks' => 1, 'group2_data' => 47],
        18 => ['total' => 901, 'ec_per_block' => 26,
            'group1_blocks' => 9, 'group1_data' => 43, 'group2_blocks' => 4, 'group2_data' => 44],
        19 => ['total' => 991, 'ec_per_block' => 26,
            'group1_blocks' => 3, 'group1_data' => 44, 'group2_blocks' => 11, 'group2_data' => 45],
        20 => ['total' => 1085, 'ec_per_block' => 26,
            'group1_blocks' => 3, 'group1_data' => 41, 'group2_blocks' => 13, 'group2_data' => 42],
    ];

    /**
     * Positions des motifs d'alignement par version.
     *
     * @var array<int, list<int>>
     */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
        11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62], 14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74], 17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82],
        19 => [6, 30, 58, 86], 20 => [6, 34, 62, 90],
    ];

    /** Bits d'information de version, versions 7 et au-delà. */
    private const VERSION_BITS = [
        7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3, 11 => 0x0BBF6, 12 => 0x0C762,
        13 => 0x0D847, 14 => 0x0E60D, 15 => 0x0F928, 16 => 0x10B78, 17 => 0x1145D, 18 => 0x12A17,
        19 => 0x13532, 20 => 0x149A6,
    ];

    /** Chaînes d'information de format, niveau M, masques 0 à 7. */
    private const FORMAT_BITS = [
        0x5412, 0x5125, 0x5E7C, 0x5B4B, 0x45F9, 0x40CE, 0x4F97, 0x4AA0,
    ];

    /**
     * Matrice aplatie, indexée par `y * size + x`.
     *
     * Une grille de grille serait plus lisible à l'écriture, mais la
     * construction se fait par affectation indexée : une seule dimension
     * garde le type vérifiable de bout en bout, et évite un tableau de
     * tableaux recopié à chaque essai de masque.
     *
     * @var array<int, int> 1 = module noir, 0 = blanc
     */
    private array $modules = [];

    /** @var array<int, bool> modules de fonction, non masquables */
    private array $reserved = [];

    private int $size = 0;

    private function __construct(public readonly int $version)
    {
        $this->size = 17 + 4 * $version;
        $this->modules = array_fill(0, $this->size * $this->size, 0);
        $this->reserved = array_fill(0, $this->size * $this->size, false);
    }

    /**
     * Encode une chaîne et renvoie la matrice de modules.
     *
     * @return list<list<int>>
     */
    public static function encode(string $text): array
    {
        $version = self::versionFor(strlen($text));
        $code = new self($version);

        $code->drawFunctionPatterns();
        $code->placeCodewords(self::buildCodewords($text, $version));

        $mask = $code->chooseMask();
        $code->applyMask($mask);
        $code->drawFormat($mask);
        $code->drawVersion();

        return $code->grid();
    }

    /**
     * @return list<list<int>>
     */
    private function grid(): array
    {
        $rows = [];
        for ($y = 0; $y < $this->size; $y++) {
            $row = [];
            for ($x = 0; $x < $this->size; $x++) {
                $row[] = $this->modules[$y * $this->size + $x] ?? 0;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Rendu SVG, avec la zone de silence exigée par la norme.
     *
     * `$label` porte le nom accessible **dans** l'image, par un `<title>`,
     * plutôt que sur un conteneur qui l'entoure. Un `<div role="img">` autour
     * d'une image qui se déclare déjà comme telle empile deux fois la même
     * sémantique, et le nom se perd si le conteneur disparaît. Vide, aucun
     * titre n'est émis : une image sans nom vaut mieux qu'un nom faux.
     */
    public static function toSvg(
        string $text,
        int $moduleSize = 4,
        int $quietZone = 4,
        string $label = ''
    ): string {
        $modules = self::encode($text);
        $count = count($modules);
        $side = ($count + 2 * $quietZone) * $moduleSize;

        $path = '';
        foreach ($modules as $y => $row) {
            foreach ($row as $x => $value) {
                if ($value === 1) {
                    $path .= sprintf(
                        'M%d %dh%dv%dh-%dz',
                        ($x + $quietZone) * $moduleSize,
                        ($y + $quietZone) * $moduleSize,
                        $moduleSize,
                        $moduleSize,
                        $moduleSize
                    );
                }
            }
        }

        $title = $label === ''
            ? ''
            : sprintf('<title>%s</title>', htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img">'
            . '%s<rect width="%d" height="%d" fill="#ffffff"/><path d="%s" fill="#000000"/></svg>',
            $side,
            $side,
            $side,
            $side,
            $title,
            $side,
            $side,
            $path
        );
    }

    public static function versionFor(int $byteLength): int
    {
        foreach (self::CAPACITY as $version => $capacity) {
            if ($byteLength <= $capacity) {
                return $version;
            }
        }

        throw new RuntimeException('qr.error.too_long');
    }

    // --- Codets ---------------------------------------------------------------

    /**
     * @return list<int>
     */
    private static function buildCodewords(string $text, int $version): array
    {
        $spec = self::BLOCKS[$version];
        $dataCount = $spec['group1_blocks'] * $spec['group1_data']
            + $spec['group2_blocks'] * $spec['group2_data'];

        $bits = '';
        // Mode octet.
        $bits .= '0100';
        // Le compteur de longueur fait 8 bits jusqu'à la version 9, 16 ensuite.
        $bits .= str_pad(decbin(strlen($text)), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);

        foreach (str_split($text) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        // Terminateur, puis alignement sur un octet.
        $capacityBits = $dataCount * 8;
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        // Octets de remplissage alternés, imposés par la norme.
        $padding = ['11101100', '00010001'];
        $index = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $padding[$index % 2];
            $index++;
        }

        $data = [];
        foreach (str_split($bits, 8) as $byte) {
            $data[] = (int) bindec($byte);
        }

        return self::interleave($data, $spec);
    }

    /**
     * Découpe en blocs, calcule la correction d'erreur et entrelace.
     *
     * @param list<int> $data
     * @param array{total: int, ec_per_block: int, group1_blocks: int,
     *     group1_data: int, group2_blocks: int, group2_data: int} $spec
     *
     * @return list<int>
     */
    private static function interleave(array $data, array $spec): array
    {
        $blocks = [];
        $offset = 0;

        $groups = [
            [$spec['group1_blocks'], $spec['group1_data']],
            [$spec['group2_blocks'], $spec['group2_data']],
        ];

        foreach ($groups as [$count, $length]) {
            for ($index = 0; $index < $count; $index++) {
                $blocks[] = array_slice($data, $offset, $length);
                $offset += $length;
            }
        }

        $correction = [];
        foreach ($blocks as $block) {
            $correction[] = ReedSolomon::encode($block, $spec['ec_per_block']);
        }

        $result = [];

        $longest = 0;
        foreach ($blocks as $block) {
            $longest = max($longest, count($block));
        }
        for ($position = 0; $position < $longest; $position++) {
            foreach ($blocks as $block) {
                if (isset($block[$position])) {
                    $result[] = $block[$position];
                }
            }
        }

        for ($position = 0; $position < $spec['ec_per_block']; $position++) {
            foreach ($correction as $block) {
                $result[] = $block[$position] ?? 0;
            }
        }

        return $result;
    }

    // --- Matrice ---------------------------------------------------------------

    private function moduleAt(int $x, int $y): int
    {
        return $this->modules[$y * $this->size + $x] ?? 0;
    }

    private function set(int $x, int $y, int $value): void
    {
        $this->modules[$y * $this->size + $x] = $value;
    }

    private function isReserved(int $x, int $y): bool
    {
        return $this->reserved[$y * $this->size + $x] ?? false;
    }

    private function drawFunctionPatterns(): void
    {
        foreach ([[0, 0], [$this->size - 7, 0], [0, $this->size - 7]] as [$x, $y]) {
            $this->drawFinder($x, $y);
        }

        // Motifs de synchronisation.
        for ($index = 8; $index < $this->size - 8; $index++) {
            $value = $index % 2 === 0 ? 1 : 0;
            $this->setFunction($index, 6, $value);
            $this->setFunction(6, $index, $value);
        }

        foreach (self::ALIGNMENT[$this->version] as $x) {
            foreach (self::ALIGNMENT[$this->version] as $y) {
                // Les motifs d'alignement n'empiètent jamais sur les
                // détecteurs de position.
                if (($x < 8 && $y < 8) || ($x < 8 && $y > $this->size - 9) || ($x > $this->size - 9 && $y < 8)) {
                    continue;
                }
                $this->drawAlignment($x, $y);
            }
        }

        // Module toujours noir.
        $this->setFunction(8, $this->size - 8, 1);

        // Zones réservées aux informations de format et de version.
        for ($index = 0; $index < 9; $index++) {
            $this->reserve($index, 8);
            $this->reserve(8, $index);
        }
        for ($index = 0; $index < 8; $index++) {
            $this->reserve($this->size - 1 - $index, 8);
            $this->reserve(8, $this->size - 1 - $index);
        }

        if ($this->version >= 7) {
            for ($x = 0; $x < 6; $x++) {
                for ($y = 0; $y < 3; $y++) {
                    $this->reserve($x, $this->size - 11 + $y);
                    $this->reserve($this->size - 11 + $y, $x);
                }
            }
        }
    }

    private function drawFinder(int $originX, int $originY): void
    {
        for ($x = -1; $x <= 7; $x++) {
            for ($y = -1; $y <= 7; $y++) {
                $targetX = $originX + $x;
                $targetY = $originY + $y;
                if ($targetX < 0 || $targetY < 0 || $targetX >= $this->size || $targetY >= $this->size) {
                    continue;
                }

                $inRing = ($x === 0 || $x === 6) && $y >= 0 && $y <= 6;
                $inRing = $inRing || (($y === 0 || $y === 6) && $x >= 0 && $x <= 6);
                $inCore = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;

                $this->setFunction($targetX, $targetY, $inRing || $inCore ? 1 : 0);
            }
        }
    }

    private function drawAlignment(int $centreX, int $centreY): void
    {
        for ($x = -2; $x <= 2; $x++) {
            for ($y = -2; $y <= 2; $y++) {
                $ring = max(abs($x), abs($y));
                $this->setFunction($centreX + $x, $centreY + $y, $ring !== 1 ? 1 : 0);
            }
        }
    }

    /**
     * @param list<int> $codewords
     */
    private function placeCodewords(array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $position = 0;
        $upward = true;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            // La colonne de synchronisation verticale est sautée.
            if ($right === 6) {
                $right = 5;
            }

            for ($step = 0; $step < $this->size; $step++) {
                $y = $upward ? $this->size - 1 - $step : $step;

                foreach ([$right, $right - 1] as $x) {
                    if ($this->isReserved($x, $y)) {
                        continue;
                    }

                    $this->set($x, $y, isset($bits[$position]) && $bits[$position] === '1' ? 1 : 0);
                    $position++;
                }
            }

            $upward = !$upward;
        }
    }

    private function chooseMask(): int
    {
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        $original = $this->modules;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->modules = $original;
            $this->applyMask($mask);
            $this->drawFormat($mask);

            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $mask;
            }
        }

        $this->modules = $original;

        return $best;
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isReserved($x, $y)) {
                    continue;
                }

                if (self::maskCondition($mask, $x, $y)) {
                    $this->set($x, $y, $this->moduleAt($x, $y) ^ 1);
                }
            }
        }
    }

    public static function maskCondition(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
            6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            default => (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0,
        };
    }

    private function drawFormat(int $mask): void
    {
        $bits = self::FORMAT_BITS[$mask];

        for ($index = 0; $index < 15; $index++) {
            $bit = ($bits >> $index) & 1;

            // Copie près du détecteur haut-gauche.
            if ($index < 6) {
                $this->set(8, $index, $bit);
            } elseif ($index === 6) {
                $this->set(8, 7, $bit);
            } elseif ($index === 7) {
                $this->set(8, 8, $bit);
            } elseif ($index === 8) {
                $this->set(7, 8, $bit);
            } else {
                $this->set(14 - $index, 8, $bit);
            }

            // Copie répartie sur les deux autres détecteurs.
            if ($index < 8) {
                $this->set($this->size - 1 - $index, 8, $bit);
            } else {
                $this->set(8, $this->size - 15 + $index, $bit);
            }
        }

        $this->set(8, $this->size - 8, 1);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }

        $bits = self::VERSION_BITS[$this->version];

        for ($index = 0; $index < 18; $index++) {
            $bit = ($bits >> $index) & 1;
            $x = intdiv($index, 3);
            $y = $this->size - 11 + ($index % 3);

            $this->set($x, $y, $bit);
            $this->set($y, $x, $bit);
        }
    }

    /**
     * Pénalité normalisée, servant à choisir le masque le plus lisible.
     */
    private function penalty(): int
    {
        $penalty = 0;

        // Règle 1 : suites de cinq modules identiques ou plus.
        for ($pass = 0; $pass < 2; $pass++) {
            for ($outer = 0; $outer < $this->size; $outer++) {
                $run = 1;
                for ($inner = 1; $inner < $this->size; $inner++) {
                    $current = $pass === 0
                        ? $this->moduleAt($inner, $outer)
                        : $this->moduleAt($outer, $inner);
                    $previous = $pass === 0
                        ? $this->moduleAt($inner - 1, $outer)
                        : $this->moduleAt($outer, $inner - 1);

                    if ($current === $previous) {
                        $run++;
                        continue;
                    }

                    if ($run >= 5) {
                        $penalty += 3 + ($run - 5);
                    }
                    $run = 1;
                }
                if ($run >= 5) {
                    $penalty += 3 + ($run - 5);
                }
            }
        }

        // Règle 2 : blocs 2 × 2 de même couleur.
        for ($y = 0; $y < $this->size - 1; $y++) {
            for ($x = 0; $x < $this->size - 1; $x++) {
                $value = $this->moduleAt($x, $y);
                if ($value === $this->moduleAt($x + 1, $y)
                    && $value === $this->moduleAt($x, $y + 1)
                    && $value === $this->moduleAt($x + 1, $y + 1)) {
                    $penalty += 3;
                }
            }
        }

        // Règle 3 : motifs ressemblant à un détecteur de position.
        $patterns = [[1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0], [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1]];
        for ($line = 0; $line < $this->size; $line++) {
            for ($start = 0; $start <= $this->size - 11; $start++) {
                foreach ($patterns as $pattern) {
                    $horizontal = true;
                    $vertical = true;
                    for ($index = 0; $index < 11; $index++) {
                        $horizontal = $horizontal && $this->moduleAt($start + $index, $line) === $pattern[$index];
                        $vertical = $vertical && $this->moduleAt($line, $start + $index) === $pattern[$index];
                    }
                    if ($horizontal) {
                        $penalty += 40;
                    }
                    if ($vertical) {
                        $penalty += 40;
                    }
                }
            }
        }

        // Règle 4 : déséquilibre entre modules noirs et blancs.
        $dark = array_sum($this->modules);
        $ratio = (int) (abs($dark * 100 / ($this->size * $this->size) - 50) / 5);
        $penalty += $ratio * 10;

        return $penalty;
    }

    private function setFunction(int $x, int $y, int $value): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }

        $this->set($x, $y, $value);
        $this->reserved[$y * $this->size + $x] = true;
    }

    private function reserve(int $x, int $y): void
    {
        if ($x >= 0 && $y >= 0 && $x < $this->size && $y < $this->size) {
            $this->reserved[$y * $this->size + $x] = true;
        }
    }
}
