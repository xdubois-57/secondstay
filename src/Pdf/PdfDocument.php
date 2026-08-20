<?php

declare(strict_types=1);

namespace SecondStay\Pdf;

/**
 * Générateur PDF minimal, sans dépendance.
 *
 * SecondStay produit des contrats : il faut donc écrire un PDF sur un
 * hébergement mutualisé, sans Composer, sans binaire externe et sans envoyer
 * le contenu d'un contrat chez un tiers. Le champ couvert est volontairement
 * étroit — texte mis en page, titres, paires libellé/valeur, tableaux simples
 * — ce qui suffit très largement à un contrat de location.
 *
 * Les polices sont les polices standard du format, en `WinAnsiEncoding` : rien
 * n'est embarqué, et les quatre langues du produit sont couvertes.
 */
final class PdfDocument
{
    /** A4 en points typographiques. */
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;
    public const MARGIN = 56.0;

    private const LEADING = 1.35;

    /** @var list<string> flux de contenu, une entrée par page */
    private array $pages = [];

    private string $current = '';

    private float $cursor = 0.0;

    private bool $started = false;

    /** @var array<string, string> */
    private array $info;

    /**
     * @param array<string, string> $info titre, auteur, sujet
     */
    public function __construct(array $info = [])
    {
        $this->info = $info;
    }

    public function contentWidth(): float
    {
        return self::WIDTH - 2 * self::MARGIN;
    }

    public function addPage(): void
    {
        if ($this->started) {
            $this->pages[] = $this->current;
        }

        $this->started = true;
        $this->current = '';
        $this->cursor = self::HEIGHT - self::MARGIN;
    }

    /**
     * Réserve la hauteur demandée, en changeant de page si nécessaire.
     */
    public function reserve(float $height): void
    {
        if (!$this->started) {
            $this->addPage();
        }

        if ($this->cursor - $height < self::MARGIN) {
            $this->addPage();
        }
    }

    public function title(string $text): void
    {
        $this->text($text, PdfFont::Bold, 18.0);
        $this->spacer(6.0);
    }

    public function heading(string $text): void
    {
        $this->spacer(8.0);
        $this->text($text, PdfFont::Bold, 12.0);
        $this->spacer(2.0);
    }

    public function paragraph(string $text, float $size = 10.0): void
    {
        $this->text($text, PdfFont::Regular, $size);
        $this->spacer(4.0);
    }

    public function small(string $text): void
    {
        $this->text($text, PdfFont::Regular, 8.0);
        $this->spacer(3.0);
    }

    /**
     * Ligne « libellé … valeur », la valeur alignée à droite.
     */
    public function keyValue(string $label, string $value, bool $strong = false): void
    {
        $size = 10.0;
        $height = $size * self::LEADING;
        $this->reserve($height);

        $font = $strong ? PdfFont::Bold : PdfFont::Regular;
        $encodedLabel = WinAnsi::encode($label);
        $encodedValue = WinAnsi::encode($value);

        $baseline = $this->cursor - $size;
        $right = self::WIDTH - self::MARGIN - $font->widthOf($encodedValue) * $size / 1000;

        $this->current .= $this->show($encodedLabel, PdfFont::Regular, $size, self::MARGIN, $baseline);
        $this->current .= $this->show($encodedValue, $font, $size, $right, $baseline);

        $this->cursor -= $height;
    }

    /**
     * Tableau simple à colonnes de largeurs proportionnelles.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     * @param list<float>        $weights poids relatifs des colonnes
     */
    public function table(array $headers, array $rows, array $weights = []): void
    {
        $columns = count($headers);
        if ($columns === 0) {
            return;
        }

        if (count($weights) !== $columns) {
            $weights = array_fill(0, $columns, 1.0);
        }

        $total = array_sum($weights);
        $widths = array_map(fn (float $weight): float => $this->contentWidth() * $weight / $total, $weights);

        $this->row($headers, $widths, PdfFont::Bold);
        $this->rule();

        foreach ($rows as $row) {
            $this->row($row, $widths, PdfFont::Regular);
        }

        $this->spacer(6.0);
    }

    public function rule(): void
    {
        $this->reserve(6.0);
        $y = $this->cursor - 2.0;

        $this->current .= sprintf(
            "0.7 G 0.5 w %.2F %.2F m %.2F %.2F l S\n",
            self::MARGIN,
            $y,
            self::WIDTH - self::MARGIN,
            $y
        );

        $this->cursor -= 6.0;
    }

    public function spacer(float $height): void
    {
        if (!$this->started) {
            $this->addPage();
        }

        $this->cursor -= $height;
    }

    /**
     * Assemble le fichier complet.
     */
    public function output(): string
    {
        if ($this->started) {
            $this->pages[] = $this->current;
            $this->started = false;
            $this->current = '';
        }

        if ($this->pages === []) {
            $this->pages[] = '';
        }

        $pageCount = count($this->pages);

        // 1 catalogue + 1 arbre de pages + 3 polices + N pages + N contenus.
        $fontStart = 3;
        $pageStart = $fontStart + 3;
        $contentStart = $pageStart + $pageCount;

        $objects = [];

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $kids = [];
        for ($index = 0; $index < $pageCount; $index++) {
            $kids[] = sprintf('%d 0 R', $pageStart + $index);
        }
        $objects[2] = sprintf(
            "<< /Type /Pages /Count %d /Kids [%s] >>",
            $pageCount,
            implode(' ', $kids)
        );

        foreach ([PdfFont::Regular, PdfFont::Bold, PdfFont::Italic] as $offset => $font) {
            $objects[$fontStart + $offset] = sprintf(
                "<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>",
                $font->value
            );
        }

        $resources = sprintf(
            '<< /Font << /F1 %d 0 R /F2 %d 0 R /F3 %d 0 R >> >>',
            $fontStart,
            $fontStart + 1,
            $fontStart + 2
        );

        foreach ($this->pages as $index => $stream) {
            $objects[$pageStart + $index] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>",
                self::WIDTH,
                self::HEIGHT,
                $resources,
                $contentStart + $index
            );

            $objects[$contentStart + $index] = $this->streamObject($stream);
        }

        $infoId = $contentStart + $pageCount;
        $objects[$infoId] = $this->infoObject();

        return $this->assemble($objects, $infoId);
    }

    // --- Mise en page ----------------------------------------------------------

    /**
     * Écrit un texte en le repliant sur la largeur utile.
     */
    private function text(string $text, PdfFont $font, float $size): void
    {
        $height = $size * self::LEADING;

        foreach (explode("\n", WinAnsi::encode($text)) as $paragraph) {
            $lines = $this->wrap($paragraph, $font, $size, $this->contentWidth());

            foreach ($lines as $line) {
                $this->reserve($height);
                $this->current .= $this->show($line, $font, $size, self::MARGIN, $this->cursor - $size);
                $this->cursor -= $height;
            }
        }
    }

    /**
     * @param list<string> $cells
     * @param list<float>  $widths
     */
    private function row(array $cells, array $widths, PdfFont $font): void
    {
        $size = 9.5;
        $height = $size * self::LEADING;

        // Chaque cellule est repliée d'abord : la ligne prend la hauteur de
        // la cellule la plus haute, sinon un libellé long déborderait.
        $wrapped = [];
        $lines = 1;
        foreach ($cells as $index => $cell) {
            $width = ($widths[$index] ?? $this->contentWidth()) - 6.0;
            $wrapped[$index] = $this->wrap(WinAnsi::encode($cell), $font, $size, $width);
            $lines = max($lines, count($wrapped[$index]));
        }

        $this->reserve($height * $lines);
        $top = $this->cursor;

        foreach ($wrapped as $index => $cellLines) {
            $x = self::MARGIN;
            for ($before = 0; $before < $index; $before++) {
                $x += $widths[$before] ?? 0.0;
            }

            foreach ($cellLines as $offset => $line) {
                $this->current .= $this->show($line, $font, $size, $x, $top - $size - $offset * $height);
            }
        }

        $this->cursor = $top - $height * $lines;
    }

    /**
     * @return list<string>
     */
    private function wrap(string $encoded, PdfFont $font, float $size, float $width): array
    {
        if ($encoded === '') {
            return [''];
        }

        $lines = [];
        $line = '';

        foreach (explode(' ', $encoded) as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;

            if ($font->widthOf($candidate) * $size / 1000 <= $width || $line === '') {
                $line = $candidate;
                continue;
            }

            $lines[] = $line;
            $line = $word;
        }

        $lines[] = $line;

        return $lines;
    }

    private function show(string $encoded, PdfFont $font, float $size, float $x, float $y): string
    {
        return sprintf(
            "BT /%s %.2F Tf 0 g %.2F %.2F Td (%s) Tj ET\n",
            $font->resourceName(),
            $size,
            $x,
            $y,
            WinAnsi::escape($encoded)
        );
    }

    // --- Sérialisation -----------------------------------------------------------

    private function streamObject(string $stream): string
    {
        $compressed = function_exists('gzcompress') ? gzcompress($stream, 6) : false;

        if ($compressed !== false && strlen($compressed) < strlen($stream)) {
            return sprintf(
                "<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                strlen($compressed),
                $compressed
            );
        }

        return sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($stream), $stream);
    }

    private function infoObject(): string
    {
        $fields = '';
        foreach (['Title', 'Author', 'Subject', 'Creator'] as $key) {
            $value = $this->info[strtolower($key)] ?? '';
            if ($value === '') {
                continue;
            }
            $fields .= sprintf(' /%s (%s)', $key, WinAnsi::escape(WinAnsi::encode($value)));
        }

        // Date fixée par l'appelant plutôt que par l'horloge : deux
        // générations du même contrat doivent produire le même fichier.
        $date = $this->info['date'] ?? gmdate('YmdHis');
        $fields .= sprintf(" /CreationDate (D:%s%s)", $date, "Z");

        return '<<' . $fields . ' >>';
    }

    /**
     * @param array<int, string> $objects
     */
    private function assemble(array $objects, int $infoId): string
    {
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xref = strlen($pdf);
        $count = count($objects) + 1;

        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $count);
        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $count,
            $infoId,
            $xref
        );

        return $pdf;
    }
}
