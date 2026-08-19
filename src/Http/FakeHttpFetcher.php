<?php

declare(strict_types=1);

namespace SecondStay\Http;

use RuntimeException;

/**
 * Fetcher factice pour les tests : aucune sortie réseau (TESTING.md §8).
 */
final class FakeHttpFetcher implements HttpFetcher
{
    /** @var array<string, array{status: int, headers: array<string, string>, body: string}> */
    private array $responses = [];

    /** @var list<string> */
    public array $requestedUrls = [];

    public function __construct(private readonly UrlGuard $guard = new UrlGuard())
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function addResponse(string $url, string $body, int $status = 200, array $headers = []): void
    {
        $this->responses[$url] = ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addJsonResponse(string $url, array $payload, int $status = 200): void
    {
        $this->addResponse(
            $url,
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['content-type' => 'application/json']
        );
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, final_url: string}
     */
    public function get(string $url, array $headers = []): array
    {
        $inspection = $this->guard->inspect($url);
        if ($inspection['ok'] === false) {
            throw new RuntimeException('Requête sortante refusée : ' . $inspection['reason']);
        }

        $this->requestedUrls[] = $url;
        $response = $this->responses[$url] ?? ['status' => 404, 'headers' => [], 'body' => ''];

        return $response + ['final_url' => $url];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>|null
     */
    public function getJson(string $url, array $headers = []): ?array
    {
        $response = $this->get($url, $headers);
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
        $response = $this->get($url, $headers);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Téléchargement factice indisponible.');
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Destination inaccessible.');
        }

        file_put_contents($destination, $response['body']);

        return strlen($response['body']);
    }
}
