<?php

declare(strict_types=1);

namespace SecondStay\Core\Http;

/**
 * @phpstan-type CookieOptions array{
 *     expires?: int,
 *     path?: string,
 *     domain?: string,
 *     secure?: bool,
 *     httponly?: bool,
 *     samesite?: 'Lax'|'Strict'|'None'
 * }
 */
class Response
{
    /** @var array<string, string> */
    protected array $headers = [];

    /** @var list<array{name: string, value: string, options: CookieOptions}> */
    protected array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        protected string $content = '',
        protected int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @param mixed $data
     */
    public static function json($data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($encoded === false ? '{}' : $encoded, $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): static
    {
        $this->headers[strtolower($name)] = $value;

        return $this;
    }

    /**
     * @param CookieOptions $options
     */
    public function withCookie(string $name, string $value, array $options = []): static
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return list<array{name: string, value: string, options: CookieOptions}>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($this->canonicalHeaderName($name) . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options']);
            }
        }

        echo $this->content;
    }

    private function canonicalHeaderName(string $name): string
    {
        return implode('-', array_map(
            static fn (string $part): string => ucfirst($part),
            explode('-', $name)
        ));
    }
}
