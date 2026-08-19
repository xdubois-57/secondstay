<?php

declare(strict_types=1);

namespace SecondStay\Core\Http;

final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, mixed>  $server
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
        public readonly string $body = '',
        public readonly string $basePath = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $basePath = self::detectBasePath($server);

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $path = '/' . ltrim($path, '/');

        $body = '';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $raw = file_get_contents('php://input');
            $body = $raw === false ? '' : $raw;
        }

        return new self(
            $method,
            $path,
            $_GET,
            $_POST,
            $server,
            array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $_COOKIE),
            $_FILES,
            $body,
            $basePath,
        );
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function detectBasePath(array $server): string
    {
        $script = (string) ($server['SCRIPT_NAME'] ?? '');
        if ($script === '') {
            return '';
        }

        $directory = str_replace('\\', '/', dirname($script));
        if ($directory === '/' || $directory === '.') {
            return '';
        }

        // Document root = racine du depot : /public reste un detail interne.
        if (str_ends_with($directory, '/public')) {
            $directory = substr($directory, 0, -strlen('/public'));
        }

        return rtrim($directory, '/');
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        if ($value === null && in_array(strtolower($name), ['content-type', 'content-length'], true)) {
            $value = $this->server[strtoupper(str_replace('-', '_', $name))] ?? null;
        }

        return is_string($value) ? $value : null;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }

        return $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function inputArray(string $key): array
    {
        $value = $this->post[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $proto = $this->server['HTTP_X_FORWARDED_PROTO'] ?? '';

        return is_string($proto) && strtolower($proto) === 'https';
    }

    public function ip(): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }

    public function userAgent(): string
    {
        return $this->header('User-Agent') ?? '';
    }

    public function acceptLanguage(): string
    {
        return $this->header('Accept-Language') ?? '';
    }

    /**
     * Vrai lorsque la requête est une navigation de document.
     *
     * Un service worker, un préchargement ou un second onglet émettent aussi
     * des requêtes GET authentifiées : elles ne doivent pas consommer les
     * messages flash destinés à la page suivante réellement affichée.
     */
    public function isDocumentNavigation(): bool
    {
        // `Sec-Fetch-Mode` fait foi : lorsqu'un service worker relaie une
        // navigation, le mode reste `navigate` alors que la destination
        // devient `empty`. Se fier à la seule destination reviendrait à
        // perdre les messages flash dès qu'un service worker est installé.
        $mode = $this->header('Sec-Fetch-Mode');
        if ($mode !== null && strtolower($mode) === 'navigate') {
            return true;
        }

        $destination = $this->header('Sec-Fetch-Dest');
        if ($destination !== null) {
            return strtolower($destination) === 'document';
        }

        if ($mode !== null) {
            return false;
        }

        // Navigateurs sans en-têtes `Sec-Fetch-*` : on retombe sur `Accept`.
        $accept = $this->header('Accept');
        if ($accept === null || $accept === '') {
            // Client qui ne se décrit pas : on suppose une navigation, comme
            // avant l'apparition des en-têtes `Sec-Fetch-*`.
            return true;
        }

        $accept = strtolower($accept);

        return str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    public function host(): string
    {
        $host = $this->server['HTTP_HOST'] ?? ($this->server['SERVER_NAME'] ?? 'localhost');

        return is_string($host) ? $host : 'localhost';
    }

    public function baseUrl(): string
    {
        return ($this->isSecure() ? 'https://' : 'http://') . $this->host() . $this->basePath;
    }

    public function withPath(string $path): self
    {
        return new self(
            $this->method,
            '/' . ltrim($path, '/'),
            $this->query,
            $this->post,
            $this->server,
            $this->cookies,
            $this->files,
            $this->body,
            $this->basePath,
        );
    }
}
