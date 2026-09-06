<?php

declare(strict_types=1);

namespace SecondStay\Http;

/**
 * Fetcher de test adossé à des fixtures sur disque (TESTING.md §8).
 *
 * Il répond depuis un fichier lorsqu'une fixture existe pour l'URL demandée,
 * et **délègue au fetcher réel** sinon. Deux conséquences utiles :
 *
 * - un scénario de bout en bout peut faire lire au produit une vraie page
 *   HTML, sans sortie réseau et sans dépendre d'un site tiers ;
 * - tout ce qui n'a pas de fixture reste soumis au garde SSRF, puisque la
 *   requête part réellement. Une source qui pointe vers le réseau interne est
 *   donc refusée pendant le test comme elle le serait en production.
 *
 * Il n'est activable que par variable d'environnement, comme les autres
 * fournisseurs factices.
 */
final class FixtureHttpFetcher implements HttpFetcher
{
    public const NAME = 'fixtures';

    public function __construct(
        private readonly string $directory,
        private readonly HttpFetcher $fallback,
    ) {
    }

    /**
     * Enregistre une fixture pour une URL.
     */
    public function store(string $url, string $body, int $status = 200): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            return;
        }

        file_put_contents(
            $this->pathFor($url),
            (string) json_encode([
                'url' => $url,
                'status' => $status,
                'body' => $body,
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    public function purge(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, final_url: string}
     */
    public function get(string $url, array $headers = []): array
    {
        $fixture = $this->read($url);
        if ($fixture === null) {
            return $this->fallback->get($url, $headers);
        }

        return [
            'status' => $fixture['status'],
            'headers' => ['content-type' => 'text/html; charset=utf-8'],
            'body' => $fixture['body'],
            'final_url' => $url,
        ];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, final_url: string}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        $fixture = $this->read($url);
        if ($fixture === null) {
            return $this->fallback->post($url, $body, $headers);
        }

        return [
            'status' => $fixture['status'],
            'headers' => ['content-type' => 'application/json'],
            'body' => $fixture['body'],
            'final_url' => $url,
        ];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>|null
     */
    public function getJson(string $url, array $headers = []): ?array
    {
        $fixture = $this->read($url);
        if ($fixture === null) {
            return $this->fallback->getJson($url, $headers);
        }

        /** @var array<mixed>|null $decoded */
        $decoded = json_decode($fixture['body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, string> $headers
     */
    public function download(string $url, string $destination, array $headers = []): int
    {
        $fixture = $this->read($url);
        if ($fixture === null) {
            return $this->fallback->download($url, $destination, $headers);
        }

        return (int) file_put_contents($destination, $fixture['body']);
    }

    /**
     * @return array{status: int, body: string}|null
     */
    private function read(string $url): ?array
    {
        $path = $this->pathFor($url);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($contents, true);
        if (!is_array($payload)) {
            return null;
        }

        return [
            'status' => (int) ($payload['status'] ?? 200),
            'body' => (string) ($payload['body'] ?? ''),
        ];
    }

    private function pathFor(string $url): string
    {
        return $this->directory . '/' . hash('sha256', $url) . '.json';
    }
}
