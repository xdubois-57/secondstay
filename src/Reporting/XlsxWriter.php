<?php

declare(strict_types=1);

namespace SecondStay\Reporting;

use RuntimeException;
use ZipArchive;

/**
 * Écriture d'un classeur XLSX, en PHP pur (SPECIFICATIONS.md §66).
 *
 * Un `.xlsx` est une archive ZIP de documents XML : `ext-zip` suffit, et le
 * produit n'ajoute aucune dépendance — contrainte d'hébergement mutualisé,
 * comme pour le PDF et le QR.
 *
 * Ce qui est produit reste **déterministe** : mêmes données, même octet. Deux
 * exports du même mois se comparent alors avec `diff`, ce qu'un classeur
 * horodaté interdirait.
 */
final class XlsxWriter
{
    /** Types de cellule reconnus. */
    public const TEXT = 'text';
    public const NUMBER = 'number';
    public const MONEY = 'money';

    /** @var list<array{name: string, rows: list<list<array{value: string|int|float, type: string}>>}> */
    private array $sheets = [];

    /**
     * Ajoute une feuille.
     *
     * @param list<string>                                                   $headers
     * @param list<list<array{value: string|int|float, type: string}>>       $rows
     */
    public function addSheet(string $name, array $headers, array $rows): void
    {
        $headerRow = array_map(
            static fn (string $header): array => ['value' => $header, 'type' => self::TEXT],
            $headers
        );

        $this->sheets[] = [
            // Les caractères interdits dans un nom d'onglet le sont vraiment :
            // Excel refuse d'ouvrir le fichier autrement.
            'name' => $this->sheetName($name),
            'rows' => array_merge([$headerRow], $rows),
        ];
    }

    /**
     * @return array{value: string|int|float, type: string}
     */
    public static function text(string $value): array
    {
        return ['value' => $value, 'type' => self::TEXT];
    }

    /**
     * @return array{value: string|int|float, type: string}
     */
    public static function number(int|float $value): array
    {
        return ['value' => $value, 'type' => self::NUMBER];
    }

    /**
     * Montant en centimes, écrit en unités monétaires.
     *
     * La feuille sert à une comptabilité : un nombre y est un nombre, pas une
     * chaîne « 145,50 € » qu'il faudrait reconvertir.
     *
     * @return array{value: string|int|float, type: string}
     */
    public static function money(int $cents): array
    {
        return ['value' => round($cents / 100, 2), 'type' => self::MONEY];
    }

    /**
     * Écrit le classeur et renvoie ses octets.
     */
    public function output(): string
    {
        if ($this->sheets === []) {
            throw new RuntimeException('Un classeur doit contenir au moins une feuille.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'ss-xlsx-');
        if ($temporary === false) {
            throw new RuntimeException('Impossible de créer le classeur.');
        }

        $zip = new ZipArchive();
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible d’ouvrir le classeur.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelations());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelations());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', $this->sheet($sheet['rows']));
        }

        $zip->close();

        $contents = file_get_contents($temporary);
        unlink($temporary);

        if ($contents === false) {
            throw new RuntimeException('Classeur illisible après écriture.');
        }

        return $contents;
    }

    // --- Documents XML ------------------------------------------------------------

    private function contentTypes(): string
    {
        $overrides = '';
        foreach ($this->sheets as $index => $sheet) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="' . $this->escape($sheet['name']) . '"'
                . ' sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelations(): string
    {
        $relations = '';
        foreach ($this->sheets as $index => $sheet) {
            $relations .= '<Relationship Id="rId' . ($index + 1) . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }

        $relations .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relations
            . '</Relationships>';
    }

    /**
     * Deux styles seulement : texte et montant à deux décimales.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00"/></numFmts>'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    /**
     * @param list<list<array{value: string|int|float, type: string}>> $rows
     */
    private function sheet(array $rows): string
    {
        $xml = '';

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $cells = '';

            foreach ($row as $column => $cell) {
                $reference = $this->column($column) . $number;

                if ($cell['type'] === self::TEXT) {
                    // Chaîne en ligne : pas de table partagée à maintenir, et
                    // un fichier lisible tel quel.
                    $cells .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">'
                        . $this->escape((string) $cell['value']) . '</t></is></c>';
                    continue;
                }

                $style = $cell['type'] === self::MONEY ? ' s="1"' : '';
                $cells .= '<c r="' . $reference . '"' . $style . '><v>'
                    . $this->numberValue($cell['value']) . '</v></c>';
            }

            $xml .= '<row r="' . $number . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $xml . '</sheetData>'
            . '</worksheet>';
    }

    private function numberValue(string|int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        // Point décimal, jamais la virgule locale : le format du fichier ne
        // dépend pas de la langue de l'utilisateur.
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function column(int $index): string
    {
        $name = '';

        for ($current = $index; $current >= 0; $current = intdiv($current, 26) - 1) {
            $name = chr(65 + ($current % 26)) . $name;
        }

        return $name;
    }

    private function sheetName(string $name): string
    {
        $clean = str_replace(['\\', '/', '*', '[', ']', ':', '?'], ' ', trim($name));

        return mb_substr($clean === '' ? 'Feuille' : $clean, 0, 31);
    }

    private function escape(string $value): string
    {
        // Les caractères de contrôle sont interdits dans un document XML 1.0.
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
