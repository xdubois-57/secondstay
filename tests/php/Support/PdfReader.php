<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use RuntimeException;

/**
 * Lecteur PDF indépendant, écrit uniquement pour les tests.
 *
 * Il ne partage aucune ligne avec le générateur : il repart du fichier
 * produit, suit la table `xref` depuis `startxref`, résout les objets par
 * leur décalage, décompresse les flux et relit les chaînes affichées. Un
 * aller-retour réussi prouve donc que le fichier est réellement structuré
 * comme un PDF, et pas seulement qu'il contient le texte attendu.
 */
final class PdfReader
{
    /** @var array<int, string> corps de chaque objet, indexé par numéro */
    private array $objects = [];

    /** @var array<string, string> */
    private array $trailer = [];

    public function __construct(private readonly string $raw)
    {
        $this->parse();
    }

    public function version(): string
    {
        if (preg_match('/^%PDF-(\d+\.\d+)/', $this->raw, $match) !== 1) {
            throw new RuntimeException('En-tête PDF absent.');
        }

        return $match[1];
    }

    public function endsProperly(): bool
    {
        return str_ends_with(trim($this->raw), '%%EOF');
    }

    /**
     * Numéros d'objet effectivement résolus par la table des références.
     *
     * @return list<int>
     */
    public function objectNumbers(): array
    {
        $numbers = array_keys($this->objects);
        sort($numbers);

        return $numbers;
    }

    public function object(int $number): string
    {
        if (!isset($this->objects[$number])) {
            throw new RuntimeException('Objet introuvable : ' . $number);
        }

        return $this->objects[$number];
    }

    public function pageCount(): int
    {
        foreach ($this->objects as $body) {
            if (str_contains($body, '/Type /Pages') && preg_match('#/Count (\d+)#', $body, $match) === 1) {
                return (int) $match[1];
            }
        }

        throw new RuntimeException('Arbre de pages absent.');
    }

    /**
     * Numéros d'objet des pages, dans l'ordre déclaré par `/Kids`.
     *
     * @return list<int>
     */
    public function pageObjects(): array
    {
        foreach ($this->objects as $body) {
            if (!str_contains($body, '/Type /Pages')) {
                continue;
            }

            if (preg_match('#/Kids \[([^\]]*)\]#', $body, $match) !== 1) {
                break;
            }

            preg_match_all('/(\d+) 0 R/', $match[1], $kids);

            return array_map(intval(...), $kids[1]);
        }

        throw new RuntimeException('Liste des pages absente.');
    }

    /**
     * Polices déclarées par une page, sous leur nom PostScript.
     *
     * @return list<string>
     */
    public function fontsOfPage(int $page): array
    {
        $body = $this->object($page);
        preg_match_all('#/F\d (\d+) 0 R#', $body, $matches);

        $fonts = [];
        foreach ($matches[1] as $id) {
            $font = $this->object((int) $id);
            if (preg_match('#/BaseFont /([A-Za-z-]+)#', $font, $match) === 1) {
                $fonts[] = $match[1];
            }
        }

        return $fonts;
    }

    /**
     * Contenu décodé d'une page.
     */
    public function contentOfPage(int $page): string
    {
        $body = $this->object($page);
        if (preg_match('#/Contents (\d+) 0 R#', $body, $match) !== 1) {
            throw new RuntimeException('Page sans flux de contenu.');
        }

        return $this->stream((int) $match[1]);
    }

    /**
     * Texte affiché par une page, dans l'ordre des opérateurs `Tj`.
     */
    public function textOfPage(int $page): string
    {
        $content = $this->contentOfPage($page);

        preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)\s*Tj/s', $content, $matches);

        $lines = array_map(
            static fn (string $value): string => str_replace(
                ['\\(', '\\)', '\\\\'],
                ['(', ')', '\\'],
                $value
            ),
            $matches[1]
        );

        // Le texte est écrit en WinAnsi : on revient en UTF-8 pour comparer.
        return (string) iconv('CP1252', 'UTF-8', implode("\n", $lines));
    }

    /**
     * Texte de toutes les pages, dans l'ordre.
     */
    public function text(): string
    {
        $parts = [];
        foreach ($this->pageObjects() as $page) {
            $parts[] = $this->textOfPage($page);
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string, string>
     */
    public function trailer(): array
    {
        return $this->trailer;
    }

    public function info(): string
    {
        $reference = $this->trailer['Info'] ?? '';
        if (preg_match('/(\d+) 0 R/', $reference, $match) !== 1) {
            throw new RuntimeException('Dictionnaire d’information absent.');
        }

        return $this->object((int) $match[1]);
    }

    // --- Analyse ---------------------------------------------------------------

    private function parse(): void
    {
        if (preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $this->raw, $match) !== 1) {
            throw new RuntimeException('Table des références introuvable.');
        }

        $xref = (int) $match[1];
        $section = substr($this->raw, $xref);

        if (preg_match('/^xref\s+(\d+)\s+(\d+)\s/', $section, $header) !== 1) {
            throw new RuntimeException('Table des références malformée.');
        }

        $first = (int) $header[1];
        $count = (int) $header[2];

        preg_match_all('/^(\d{10}) (\d{5}) ([nf])\s*$/m', $section, $entries, PREG_SET_ORDER);

        if (count($entries) !== $count) {
            throw new RuntimeException(sprintf(
                'Table des références incomplète : %d entrées pour %d annoncées.',
                count($entries),
                $count
            ));
        }

        foreach ($entries as $index => $entry) {
            if ($entry[3] !== 'n') {
                continue;
            }

            $number = $first + $index;
            $this->objects[$number] = $this->readObjectAt($number, (int) $entry[1]);
        }

        if (preg_match('/trailer\s*<<(.*?)>>\s*startxref/s', $section, $trailer) === 1) {
            preg_match_all('#/(\w+)\s+([^/>]+)#', $trailer[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $this->trailer[$pair[1]] = trim($pair[2]);
            }
        }
    }

    private function readObjectAt(int $number, int $offset): string
    {
        $expected = $number . ' 0 obj';
        if (substr($this->raw, $offset, strlen($expected)) !== $expected) {
            throw new RuntimeException(sprintf(
                'Décalage erroné pour l’objet %d : « %s » attendu.',
                $number,
                $expected
            ));
        }

        $start = $offset + strlen($expected);
        $end = strpos($this->raw, "\nendobj", $start);

        if ($end === false) {
            throw new RuntimeException('Objet ' . $number . ' non terminé.');
        }

        return trim(substr($this->raw, $start, $end - $start));
    }

    private function stream(int $number): string
    {
        $body = $this->object($number);

        $start = strpos($body, "stream\n");
        $end = strrpos($body, "\nendstream");

        if ($start === false || $end === false) {
            throw new RuntimeException('Objet ' . $number . ' n’est pas un flux.');
        }

        $dictionary = substr($body, 0, $start);
        $data = substr($body, $start + 7, $end - $start - 7);

        if (preg_match('#/Length (\d+)#', $dictionary, $match) !== 1) {
            throw new RuntimeException('Longueur de flux absente.');
        }

        if (strlen($data) !== (int) $match[1]) {
            throw new RuntimeException(sprintf(
                'Longueur de flux incohérente : %d octets pour %d annoncés.',
                strlen($data),
                (int) $match[1]
            ));
        }

        if (str_contains($dictionary, '/FlateDecode')) {
            $inflated = @gzuncompress($data);
            if ($inflated === false) {
                throw new RuntimeException('Flux compressé illisible.');
            }

            return $inflated;
        }

        return $data;
    }
}
