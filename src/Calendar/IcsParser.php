<?php

declare(strict_types=1);

namespace SecondStay\Calendar;

/**
 * Lecture d'un flux iCalendar (RFC 5545, SPECIFICATIONS.md §52).
 *
 * Écrit indépendamment du générateur : les deux ne partagent aucune ligne de
 * code. C'est délibéré — un lecteur qui réutiliserait le code du générateur ne
 * prouverait rien, puisqu'il reproduirait ses erreurs.
 *
 * Ce qui est lu est volontairement minimal : un identifiant, un début, une
 * fin, un résumé. Un flux de plateforme ne dit rien de plus d'utile, et tout
 * ce qui est lu doit être défendu.
 */
final class IcsParser
{
    /** Nombre maximal d'événements retenus dans un flux. */
    public const MAX_EVENTS = 2000;

    /** Taille maximale acceptée, en octets. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @return list<array{uid: string, start: string, end: string, summary: string}>
     */
    public function parse(string $body): array
    {
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return [];
        }

        $events = [];
        $current = null;

        foreach ($this->unfold($body) as $line) {
            $upper = strtoupper($line);

            if ($upper === 'BEGIN:VEVENT') {
                $current = ['uid' => '', 'start' => '', 'end' => '', 'summary' => ''];
                continue;
            }

            if ($upper === 'END:VEVENT') {
                if ($current !== null) {
                    $event = $this->finish($current);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }

                $current = null;

                if (count($events) >= self::MAX_EVENTS) {
                    break;
                }

                continue;
            }

            if ($current === null) {
                continue;
            }

            $this->absorb($current, $line);
        }

        return $events;
    }

    /**
     * Rassemble les lignes repliées (RFC 5545 §3.1).
     *
     * Une continuation commence par une espace ou une tabulation ; elle
     * appartient à la ligne précédente.
     *
     * @return list<string>
     */
    private function unfold(string $body): array
    {
        $lines = [];

        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $raw) {
            if ($raw === '') {
                continue;
            }

            if (($raw[0] === ' ' || $raw[0] === "\t") && $lines !== []) {
                $lines[count($lines) - 1] .= substr($raw, 1);
                continue;
            }

            $lines[] = $raw;
        }

        return $lines;
    }

    /**
     * @param array{uid: string, start: string, end: string, summary: string} $event
     */
    private function absorb(array &$event, string $line): void
    {
        $separator = strpos($line, ':');
        if ($separator === false) {
            return;
        }

        $name = strtoupper(substr($line, 0, $separator));
        $value = substr($line, $separator + 1);

        // Les paramètres suivent le nom après un point-virgule :
        // `DTSTART;VALUE=DATE` reste un `DTSTART`.
        $parameters = '';
        $semicolon = strpos($name, ';');
        if ($semicolon !== false) {
            $parameters = substr($name, $semicolon + 1);
            $name = substr($name, 0, $semicolon);
        }

        match ($name) {
            'UID' => $event['uid'] = mb_substr(trim($value), 0, 190),
            'DTSTART' => $event['start'] = $this->date($value, $parameters),
            'DTEND' => $event['end'] = $this->date($value, $parameters),
            'SUMMARY' => $event['summary'] = $this->text($value),
            default => null,
        };
    }

    /**
     * @param array{uid: string, start: string, end: string, summary: string} $event
     *
     * @return array{uid: string, start: string, end: string, summary: string}|null
     */
    private function finish(array $event): ?array
    {
        if ($event['start'] === '') {
            return null;
        }

        // Sans fin, l'événement dure une journée : `DTEND` est exclusif, donc
        // le lendemain du début.
        if ($event['end'] === '') {
            $event['end'] = $this->nextDay($event['start']);
        }

        if ($event['end'] <= $event['start']) {
            return null;
        }

        // Un flux sans UID existe : l'identité se déduit alors de ce qui est
        // stable — les dates et le résumé.
        if ($event['uid'] === '') {
            $event['uid'] = 'sha-' . substr(
                hash('sha256', $event['start'] . $event['end'] . $event['summary']),
                0,
                32
            );
        }

        return $event;
    }

    /**
     * Date d'un `DTSTART`/`DTEND`, ramenée au jour.
     *
     * Les plateformes publient des journées entières ; un horodatage complet
     * est ramené à sa date, ce qui est la seule information qui bloque une
     * nuit.
     */
    private function date(string $value, string $parameters): string
    {
        $value = trim($value);

        // Les fuseaux sont ignorés volontairement : un blocage porte sur des
        // nuits, et une heure ne change pas la nuit occupée.
        unset($parameters);

        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $found) !== 1) {
            return '';
        }

        $day = $found[1] . '-' . $found[2] . '-' . $found[3];

        return checkdate((int) $found[2], (int) $found[3], (int) $found[1]) ? $day : '';
    }

    /**
     * Déséchappe un texte (RFC 5545 §3.3.11).
     */
    private function text(string $value): string
    {
        $decoded = str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);

        return mb_substr(trim($decoded), 0, 190);
    }

    private function nextDay(string $day): string
    {
        return (new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify('+1 day')
            ->format('Y-m-d');
    }
}
