<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use RuntimeException;

/**
 * Lecteur iCalendar indépendant, écrit uniquement pour les tests.
 *
 * Il relit le flux comme le ferait un agenda : dépliage des lignes,
 * séparation nom/paramètres/valeur, déséchappement. Il ne partage aucune ligne
 * avec le générateur, de sorte qu'un aller-retour réussi prouve que le flux
 * est réellement conforme, et pas seulement qu'il contient les bons mots.
 */
final class IcsReader
{
    /** @var list<array{name: string, params: array<string, string>, value: string}> */
    private array $properties = [];

    public function __construct(private readonly string $raw)
    {
        $this->parse();
    }

    /**
     * Toutes les lignes se terminent-elles par CRLF, comme la norme l'exige ?
     */
    public function usesCrLf(): bool
    {
        return preg_match('/(?<!\r)\n/', $this->raw) !== 1 && str_ends_with($this->raw, "\r\n");
    }

    /**
     * Aucune ligne ne dépasse-t-elle 75 octets ?
     */
    public function respectsLineLimit(): int
    {
        $longest = 0;
        foreach (explode("\r\n", $this->raw) as $line) {
            $longest = max($longest, strlen($line));
        }

        return $longest;
    }

    public function value(string $name): string
    {
        foreach ($this->properties as $property) {
            if ($property['name'] === strtoupper($name)) {
                return $property['value'];
            }
        }

        throw new RuntimeException('Propriété absente : ' . $name);
    }

    public function has(string $name): bool
    {
        foreach ($this->properties as $property) {
            if ($property['name'] === strtoupper($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Événements, chacun sous forme de tableau nom => valeur.
     *
     * @return list<array<string, string>>
     */
    public function events(): array
    {
        $events = [];
        $current = null;

        foreach ($this->properties as $property) {
            if ($property['name'] === 'BEGIN' && $property['value'] === 'VEVENT') {
                $current = [];
                continue;
            }

            if ($property['name'] === 'END' && $property['value'] === 'VEVENT') {
                if ($current === null) {
                    throw new RuntimeException('END:VEVENT sans BEGIN.');
                }
                $events[] = $current;
                $current = null;
                continue;
            }

            if ($current !== null) {
                $key = $property['name'];
                // Les paramètres comptent : `DTSTART;VALUE=DATE` n'est pas
                // `DTSTART` tout court pour un agenda.
                if ($property['params'] !== []) {
                    $key .= ';' . implode(';', array_map(
                        static fn (string $k, string $v): string => $k . '=' . $v,
                        array_keys($property['params']),
                        $property['params']
                    ));
                }
                $current[$key] = $property['value'];
            }
        }

        if ($current !== null) {
            throw new RuntimeException('BEGIN:VEVENT non refermé.');
        }

        return $events;
    }

    /**
     * Vérifie la structure d'ensemble et renvoie le nombre d'événements.
     */
    public function assertWellFormed(): int
    {
        $first = $this->properties[0] ?? null;
        $last = $this->properties[count($this->properties) - 1] ?? null;

        if ($first === null || $first['name'] !== 'BEGIN' || $first['value'] !== 'VCALENDAR') {
            throw new RuntimeException('Le flux ne commence pas par BEGIN:VCALENDAR.');
        }

        if ($last === null || $last['name'] !== 'END' || $last['value'] !== 'VCALENDAR') {
            throw new RuntimeException('Le flux ne se termine pas par END:VCALENDAR.');
        }

        if ($this->value('VERSION') !== '2.0') {
            throw new RuntimeException('Version iCalendar inattendue.');
        }

        return count($this->events());
    }

    // --- Analyse -------------------------------------------------------------

    private function parse(): void
    {
        // Dépliage : une ligne commençant par une espace ou une tabulation
        // prolonge la précédente.
        $unfolded = (string) preg_replace("/\r\n[ \t]/", '', $this->raw);

        foreach (explode("\r\n", $unfolded) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new RuntimeException('Ligne sans séparateur : ' . $line);
            }

            $head = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);

            $parts = explode(';', $head);
            $name = strtoupper(array_shift($parts));

            $params = [];
            foreach ($parts as $parameter) {
                [$key, $parameterValue] = array_pad(explode('=', $parameter, 2), 2, '');
                $params[strtoupper($key)] = $parameterValue;
            }

            $this->properties[] = [
                'name' => $name,
                'params' => $params,
                'value' => self::unescape($value),
            ];
        }
    }

    public static function unescape(string $value): string
    {
        return str_replace(
            ['\\n', '\\N', '\;', '\\,', '\\\\'],
            ["\n", "\n", ';', ',', '\\'],
            $value
        );
    }
}
