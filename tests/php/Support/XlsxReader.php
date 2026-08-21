<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Lecteur XLSX indépendant, écrit uniquement pour les tests.
 *
 * Il ouvre l'archive comme le ferait un tableur : relations du classeur,
 * ordre des feuilles, puis cellules résolues par leur référence (`B7`) et non
 * par leur position dans le XML. Il ne partage aucune ligne avec
 * `XlsxWriter` : un aller-retour réussi prouve que le classeur est réellement
 * lisible, et pas seulement qu'il contient les bonnes chaînes.
 */
final class XlsxReader
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const RELATIONS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const DOCUMENT_RELATIONS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var array<string, string> chemin dans l'archive => contenu */
    private array $parts = [];

    /** @var list<string> */
    private array $sheetNames = [];

    /** @var array<string, list<array<int, array{value: string, money: bool}>>> */
    private array $sheets = [];

    public function __construct(string $bytes)
    {
        $this->openArchive($bytes);
        $this->readWorkbook();
    }

    /**
     * Noms des onglets, dans l'ordre du classeur.
     *
     * @return list<string>
     */
    public function sheetNames(): array
    {
        return $this->sheetNames;
    }

    /**
     * Lignes d'une feuille, chaque cellule rendue comme une chaîne.
     *
     * @return list<list<string>>
     */
    public function rows(string $sheet): array
    {
        $rows = [];

        foreach ($this->cells($sheet) as $row) {
            $line = [];
            $width = $row === [] ? 0 : max(array_keys($row)) + 1;
            for ($column = 0; $column < $width; $column++) {
                $line[] = $row[$column]['value'] ?? '';
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * Contenu d'une cellule désignée par sa référence (`B7`).
     */
    public function cell(string $sheet, string $reference): string
    {
        [$column, $row] = $this->splitReference($reference);
        $rows = $this->cells($sheet);

        return $rows[$row - 1][$column]['value'] ?? '';
    }

    /**
     * La cellule porte-t-elle le format monétaire à deux décimales ?
     */
    public function isMoney(string $sheet, string $reference): bool
    {
        [$column, $row] = $this->splitReference($reference);
        $rows = $this->cells($sheet);

        return $rows[$row - 1][$column]['money'] ?? false;
    }

    /**
     * Première ligne dont la cellule d'en-tête vaut exactement `$label`.
     *
     * @return list<string>
     */
    public function rowStartingWith(string $sheet, string $label): array
    {
        foreach ($this->rows($sheet) as $row) {
            if (($row[0] ?? '') === $label) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @return list<array<int, array{value: string, money: bool}>>
     */
    private function cells(string $sheet): array
    {
        if (!isset($this->sheets[$sheet])) {
            throw new RuntimeException('Feuille absente du classeur : ' . $sheet);
        }

        return $this->sheets[$sheet];
    }

    private function openArchive(string $bytes): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'ss-xlsx-read-');
        if ($temporary === false || file_put_contents($temporary, $bytes) === false) {
            throw new RuntimeException('Impossible d’écrire le classeur à relire.');
        }

        $zip = new ZipArchive();
        if ($zip->open($temporary) !== true) {
            unlink($temporary);

            throw new RuntimeException('Le classeur n’est pas une archive ZIP lisible.');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if ($name === false) {
                continue;
            }
            $contents = $zip->getFromIndex($index);
            $this->parts[$name] = $contents === false ? '' : $contents;
        }

        $zip->close();
        unlink($temporary);
    }

    private function readWorkbook(): void
    {
        $relations = [];
        foreach ($this->elements('xl/_rels/workbook.xml.rels', self::RELATIONS, 'Relationship') as $relation) {
            $relations[$relation->getAttribute('Id')] = $relation->getAttribute('Target');
        }

        foreach ($this->elements('xl/workbook.xml', self::MAIN, 'sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $target = $relations[$sheet->getAttributeNS(self::DOCUMENT_RELATIONS, 'id')] ?? '';
            if ($name === '' || $target === '') {
                throw new RuntimeException('Feuille sans nom ou sans relation.');
            }

            $this->sheetNames[] = $name;
            $this->sheets[$name] = $this->readSheet('xl/' . ltrim($target, '/'));
        }
    }

    /**
     * @return list<array<int, array{value: string, money: bool}>>
     */
    private function readSheet(string $path): array
    {
        $rows = [];

        foreach ($this->elements($path, self::MAIN, 'row') as $row) {
            $cells = [];

            foreach ($row->getElementsByTagNameNS(self::MAIN, 'c') as $cell) {
                [$column] = $this->splitReference($cell->getAttribute('r'));
                $cells[$column] = [
                    'value' => $this->cellValue($cell),
                    'money' => $cell->getAttribute('s') === '1',
                ];
            }

            $number = (int) $row->getAttribute('r');
            $rows[max(1, $number) - 1] = $cells;
        }

        ksort($rows);

        return array_values($rows);
    }

    private function cellValue(DOMElement $cell): string
    {
        if ($cell->getAttribute('t') === 'inlineStr') {
            $text = $cell->getElementsByTagNameNS(self::MAIN, 't')->item(0);

            return $text === null ? '' : $text->textContent;
        }

        $value = $cell->getElementsByTagNameNS(self::MAIN, 'v')->item(0);

        return $value === null ? '' : $value->textContent;
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(string $path, string $namespace, string $tag): array
    {
        if (!isset($this->parts[$path])) {
            throw new RuntimeException('Partie absente de l’archive : ' . $path);
        }

        $document = new DOMDocument();
        // Les entités externes n'ont rien à faire dans un classeur : le
        // lecteur de test refuse ce qu'un tableur refuserait.
        if (!$document->loadXML($this->parts[$path], LIBXML_NONET)) {
            throw new RuntimeException('XML illisible : ' . $path);
        }

        // Une requête XPath plutôt qu'un parcours récursif : elle ne dépend
        // pas de la profondeur choisie par l'écrivain.
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ns', $namespace);

        $found = [];
        $nodes = $xpath->query('//ns:' . $tag);
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $found[] = $node;
                }
            }
        }

        return $found;
    }

    /**
     * Décompose `B7` en index de colonne (base zéro) et numéro de ligne.
     *
     * @return array{int, int}
     */
    private function splitReference(string $reference): array
    {
        if (preg_match('/^([A-Z]+)([0-9]+)$/', strtoupper(trim($reference)), $matches) !== 1) {
            throw new RuntimeException('Référence de cellule invalide : ' . $reference);
        }

        $column = 0;
        foreach (str_split($matches[1]) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }

        return [$column - 1, (int) $matches[2]];
    }
}
