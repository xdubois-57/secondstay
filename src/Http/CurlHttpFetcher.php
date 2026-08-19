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
    private function request(string $url, array $headers, bool $followRedirects): array
    {
        $curl = $this->createHandle($url, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, $followRedirects);

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
            throw new RuntimeException('Requête sortante échouée : ' . ($error !== '' ? 'erreur réseau' : 'inconnue'));
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

        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $formatted,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'SecondStay',
        ]);

        return $curl;
    }

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
