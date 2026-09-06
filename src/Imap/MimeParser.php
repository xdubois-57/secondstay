<?php

declare(strict_types=1);

namespace SecondStay\Imap;

/**
 * Analyseur MIME.
 *
 * Écrit ici plutôt que délégué à `ext-imap` : cette extension est absente de
 * la plupart des hébergements mutualisés visés, et son API impose une
 * connexion ouverte pour analyser un message déjà téléchargé.
 *
 * L'analyse est **défensive** : un message reçu vient d'Internet. Aucune
 * profondeur de multipart illimitée, aucune taille non bornée, aucun jeu de
 * caractères cru sur parole.
 */
final class MimeParser
{
    /** Au-delà, un message imbriqué est une tentative d'épuisement. */
    private const MAX_DEPTH = 8;

    /** Nombre maximal de parties analysées dans un même message. */
    private const MAX_PARTS = 200;

    private int $parts = 0;

    public function parse(string $raw): MimeMessage
    {
        $this->parts = 0;

        [$headerBlock, $body] = self::split($raw);
        $headers = self::headers($headerBlock);

        $collected = ['text' => '', 'html' => '', 'attachments' => []];
        $this->walk($headers, $body, $collected, 0);

        [$fromAddress, $fromName] = self::address($headers['from'] ?? '');

        return new MimeMessage(
            $headers,
            self::decodeWords($headers['subject'] ?? ''),
            $fromAddress,
            $fromName,
            trim($collected['text']),
            $collected['html'],
            $collected['attachments'],
            self::firstIdentifier($headers['message-id'] ?? ''),
            self::firstIdentifier($headers['in-reply-to'] ?? ''),
            self::identifiers($headers['references'] ?? ''),
            self::date($headers['date'] ?? ''),
        );
    }

    /**
     * Sépare le bloc d'en-têtes du corps.
     *
     * @return array{string, string}
     */
    public static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $position = strpos($raw, "\n\n");

        if ($position === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $position), substr($raw, $position + 2)];
    }

    /**
     * En-têtes dépliés, en minuscules, dernière occurrence conservée.
     *
     * @return array<string, string>
     */
    public static function headers(string $block): array
    {
        $headers = [];
        $name = '';

        foreach (explode("\n", str_replace("\r\n", "\n", $block)) as $line) {
            if ($line === '') {
                continue;
            }

            // Une ligne commençant par une espace prolonge la précédente.
            if ($name !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $headers[$name] .= ' ' . trim($line);
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $headers[$name] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    /**
     * Adresse et nom affiché du premier destinataire d'un champ.
     *
     * @return array{string, string}
     */
    public static function address(string $field): array
    {
        $field = self::decodeWords($field);

        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $field, $match) === 1) {
            $name = trim($match[1], " \t\"'");

            return [strtolower(trim($match[2])), $name];
        }

        return [strtolower(trim($field)), ''];
    }

    /**
     * Toutes les adresses d'un champ, sans nom affiché.
     *
     * @return list<string>
     */
    public static function addresses(string $field): array
    {
        if (trim($field) === '') {
            return [];
        }

        preg_match_all('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', self::decodeWords($field), $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[0])));
    }

    /**
     * Décodage des mots encodés RFC 2047 (`=?UTF-8?B?...?=`).
     */
    public static function decodeWords(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }

        // La séparation entre deux mots encodés adjacents ne fait pas partie
        // du texte (RFC 2047 §6.2) : elle disparaît avant le décodage, sinon
        // il n'y a plus moyen de la distinguer d'une vraie espace.
        $value = (string) preg_replace('/\?=\s+=\?/', '?==?', $value);

        $decoded = preg_replace_callback(
            '/=\?([A-Za-z0-9_.:-]+)\?([BbQq])\?([^?]*)\?=/',
            static function (array $match): string {
                $charset = $match[1];
                $content = strtoupper($match[2]) === 'B'
                    ? (string) base64_decode($match[3], true)
                    : (string) quoted_printable_decode(str_replace('_', ' ', $match[3]));

                return self::toUtf8($content, $charset);
            },
            $value
        );

        return (string) $decoded;
    }

    /**
     * Conversion vers UTF-8, sans jamais échouer.
     *
     * Un jeu de caractères inconnu ou menteur ne doit pas faire perdre le
     * message : on retombe alors sur une lecture Latin-1, qui n'échoue jamais.
     */
    public static function toUtf8(string $value, string $charset): string
    {
        $charset = strtoupper(trim($charset));

        if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8') {
            return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Premier identifiant `<...>` d'un champ.
     */
    public static function firstIdentifier(string $value): string
    {
        return self::identifiers($value)[0] ?? '';
    }

    /**
     * @return list<string>
     */
    public static function identifiers(string $value): array
    {
        preg_match_all('/<([^<>\s]+)>/', $value, $matches);

        return array_values(array_map(
            static fn (string $id): string => mb_substr($id, 0, 190),
            $matches[1]
        ));
    }

    /**
     * Date du message, normalisée UTC, ou `null` si illisible.
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    // --- Parcours ---------------------------------------------------------------

    /**
     * @param array<string, string>                                                                   $headers
     * @param array{text: string, html: string,
     *     attachments: list<array{filename: string, mime: string, contents: string, content_id: string}>} $collected
     */
    private function walk(array $headers, string $body, array &$collected, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || $this->parts >= self::MAX_PARTS) {
            return;
        }

        $this->parts++;

        // La casse d'origine est conservée : les paramètres portent des noms
        // de fichiers et des mots encodés en base64, que passer en minuscules
        // détruirait. Seul le type lui-même est comparé en minuscules.
        $contentType = $headers['content-type'] ?? 'text/plain';
        $mime = strtolower(trim(explode(';', $contentType)[0]));

        if (str_starts_with($mime, 'multipart/')) {
            $boundary = self::parameter($contentType, 'boundary');
            if ($boundary === '') {
                return;
            }

            foreach (self::explodeParts($body, $boundary) as $part) {
                [$partHeaders, $partBody] = self::split($part);
                $this->walk(self::headers($partHeaders), $partBody, $collected, $depth + 1);
            }

            return;
        }

        $disposition = $headers['content-disposition'] ?? '';
        $filename = self::decodeWords(
            self::parameter($disposition, 'filename') ?: self::parameter($contentType, 'name')
        );

        $contents = self::decodeBody($body, $headers['content-transfer-encoding'] ?? '');

        $isAttachment = str_starts_with(strtolower(ltrim($disposition)), 'attachment') || $filename !== '';

        if ($isAttachment) {
            $collected['attachments'][] = [
                'filename' => $filename === '' ? 'piece-jointe' : $filename,
                'mime' => $mime,
                'contents' => $contents,
                'content_id' => trim($headers['content-id'] ?? '', '<> '),
            ];

            return;
        }

        $charset = self::parameter($contentType, 'charset');

        if ($mime === 'text/html') {
            $collected['html'] .= self::toUtf8($contents, $charset);

            return;
        }

        if (str_starts_with($mime, 'text/')) {
            $collected['text'] .= self::toUtf8($contents, $charset) . "\n";
        }
    }

    /**
     * @return list<string>
     */
    private static function explodeParts(string $body, string $boundary): array
    {
        $marker = '--' . $boundary;
        $segments = explode($marker, str_replace("\r\n", "\n", $body));

        // Le préambule et l'épilogue ne sont pas des parties.
        array_shift($segments);

        $parts = [];
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '--')) {
                break;
            }

            $parts[] = ltrim($segment, "\n");
        }

        return $parts;
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /**
     * Paramètre d'un en-tête structuré, guillemets retirés.
     */
    public static function parameter(string $header, string $name): string
    {
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $header, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*([^;\s]+)/i', $header, $match) === 1) {
            return trim($match[1], '"');
        }

        return '';
    }
}
