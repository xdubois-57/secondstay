<?php

declare(strict_types=1);

namespace SecondStay\Http;

/**
 * Frontière HTTP sortante (ARCHITECTURE.md §3).
 *
 * Toute récupération distante passe par cette interface : cela permet
 * d'appliquer les protections SSRF au même endroit et de tester sans réseau.
 */
interface HttpFetcher
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, final_url: string}
     */
    public function get(string $url, array $headers = []): array;

    /**
     * Requête POST au corps opaque (charge utile chiffrée, JSON, formulaire).
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, final_url: string}
     */
    public function post(string $url, string $body, array $headers = []): array;

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>|null
     */
    public function getJson(string $url, array $headers = []): ?array;

    /**
     * @param array<string, string> $headers
     *
     * @return int octets écrits
     */
    public function download(string $url, string $destination, array $headers = []): int;
}
