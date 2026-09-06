<?php

declare(strict_types=1);

namespace SecondStay\Http;

use RuntimeException;

/**
 * Implémentation cURL avec garde SSRF sur l'URL initiale ET sur chaque
 * redirection (SECURITY.md §16).
 */
final class CurlHttpFetcher implements HttpFetcher
{
    public const MAX_REDIRECTS = 3;
    public const MAX_BODY_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly UrlGuard $guard = new UrlGuard(),
        private readonly int $timeoutSeconds = 20,
        private readonly int $maxDownloadBytes = 200 * 1024 * 1024,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, final_url: string}
     */
    public function get(string $url, array $headers = []): array
    {
        $current = $url;

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $inspection = $this->guard->inspect($current);
            if ($inspection['ok'] === false) {
                throw new RuntimeException('Requête sortante refusée : ' . $inspection['reason']);
            }

            $response = $this->request($current, $headers, false);

            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                $location = $response['headers']['location'] ?? '';
                if ($location === '') {
                    return $response;
                }
                $current = $this->resolveRedirect($current, $location);
                continue;
            }

            return $response;
        }

        throw new RuntimeException('Trop de redirections.');
    }

    /**
     * Une requête POST n'est jamais suivie en redirection : rejouer un corps
     * sur une autre origine serait une porte ouverte au SSRF.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, final_url: string}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        $inspection = $this->guard->inspect($url);
        if ($inspection['ok'] === false) {
            throw new RuntimeException('Requête sortante refusée : ' . $inspection['reason']);
        }

        return $this->request($url, $headers, false, $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>|null
     */
    public function getJson(string $url, array $headers = []): ?array
    {
        $response = $this->get($url, $headers + ['Accept' => 'application/json']);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, string> $headers
     */
    public function download(string $url, string $destination, array $headers = []): int
    {
        $current = $url;

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $inspection = $this->guard->inspect($current);
            if ($inspection['ok'] === false) {
                throw new RuntimeException('Téléchargement refusé : ' . $inspection['reason']);
            }

            $directory = dirname($destination);
            if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
                throw new RuntimeException('Destination de téléchargement inaccessible.');
            }

            $handle = fopen($destination, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Impossible d’écrire le téléchargement.');
            }

            $curl = $this->createHandle($current, $headers);
            curl_setopt($curl, CURLOPT_FILE, $handle);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            $limit = $this->maxDownloadBytes;
            curl_setopt(
                $curl,
                CURLOPT_PROGRESSFUNCTION,
                static fn (\CurlHandle $c, int $downloadTotal, int $downloaded): int => $downloaded > $limit ? 1 : 0
            );

            $success = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $location = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            $error = curl_error($curl);
            curl_close($curl);
            fclose($handle);

            if ($success === false && $error !== '') {
                @unlink($destination);
                throw new RuntimeException('Téléchargement échoué.');
            }

            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                $current = $this->resolveRedirect($current, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                @unlink($destination);
                throw new RuntimeException('Téléchargement refusé par le serveur distant (' . $status . ').');
            }

            return filesize($destination) ?: 0;
        }

        throw new RuntimeException('Trop de redirections.');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, final_url: string}
     */
    private function request(string $url, array $headers, bool $followRedirects, ?string $postBody = null): array
    {
        $curl = $this->createHandle($url, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, $followRedirects);

        if ($postBody !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $postBody);
        }

        $responseHeaders = [];
        curl_setopt(
            $curl,
            CURLOPT_HEADERFUNCTION,
            static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            }
        );

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException(
                'Requête sortante échouée : ' . ($error !== '' ? 'erreur réseau' : 'inconnue')
            );
        }

        /** @var array<string, string> $responseHeaders */
        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($body) ? substr($body, 0, self::MAX_BODY_BYTES) : '',
            'final_url' => $url,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function createHandle(string $url, array $headers): \CurlHandle
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Initialisation cURL impossible.');
        }

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        curl_setopt_array(
            $curl,
            self::protocolRestrictionOptions() + [
                CURLOPT_HTTPHEADER => $formatted,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => 'SecondStay',
            ]
        );

        return $curl;
    }

    /**
     * Restriction des protocoles autorisés, dans la forme que l'hôte comprend.
     *
     * `CURLOPT_PROTOCOLS_STR` n'existe que si PHP a été construit avec
     * libcurl ≥ 7.85. Le produit s'installe sur un hébergement mutualisé
     * quelconque (AGENTS.md §1.6) : nommer la constante directement ferait
     * échouer **toute** requête sortante sur « Undefined constant » là où elle
     * manque — l'import de calendrier, les webhooks, le fournisseur de
     * contenu local — et non seulement la restriction elle-même.
     *
     * La restriction est appliquée dans les deux cas, jamais abandonnée : sans
     * elle, une redirection vers `file://` ou `gopher://` deviendrait
     * atteignable, ce que la garde d'URL n'attrape pas (SECURITY.md §16).
     * `constant()` plutôt qu'une constante nommée, pour que l'analyse statique
     * n'ait pas à connaître un symbole que l'hôte d'analyse peut ne pas avoir.
     *
     * @return array<int, string|int>
     */
    public static function protocolRestrictionOptions(): array
    {
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            return [
                (int) constant('CURLOPT_PROTOCOLS_STR') => 'http,https',
                (int) constant('CURLOPT_REDIR_PROTOCOLS_STR') => 'http,https',
            ];
        }

        return [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
    }

    /**
     * Reconstruit l'adresse d'une redirection relative.
     *
     * Elle est résolue ici plutôt que laissée à cURL parce que chaque saut
     * doit repasser par le contrôle SSRF : une redirection est une adresse que
     * le serveur distant choisit, et la suivre sans la revalider annulerait la
     * validation faite sur l'adresse de départ.
     */
    private function resolveRedirect(string $base, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Redirection illisible.');
        }

        $prefix = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $prefix . '/' . ltrim($location, '/');
    }
}
