<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Core\Exception\NotFoundException;

final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: array{0: class-string, 1: string}, name: string, localised: bool}> */
    private array $routes = [];

    /** @var array<string, array{pattern: string, localised: bool}> */
    private array $named = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function add(string $method, string $pattern, array $handler, string $name, bool $localised = true): self
    {
        $normalised = '/' . trim($pattern, '/');
        if ($normalised === '/') {
            $normalised = '/';
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $normalised,
            'regex' => $this->compile($normalised),
            'handler' => $handler,
            'name' => $name,
            'localised' => $localised,
        ];
        $this->named[$name] = ['pattern' => $normalised, 'localised' => $localised];

        return $this;
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function get(string $pattern, array $handler, string $name, bool $localised = true): self
    {
        return $this->add('GET', $pattern, $handler, $name, $localised);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function post(string $pattern, array $handler, string $name, bool $localised = true): self
    {
        return $this->add('POST', $pattern, $handler, $name, $localised);
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}, params: array<string, string>, name: string}
     *
     * @throws NotFoundException
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $path = '/' . trim($path, '/');
        $allowedElsewhere = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowedElsewhere = true;
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode((string) $value);
                }
            }

            return ['handler' => $route['handler'], 'params' => $params, 'name' => $route['name']];
        }

        if ($allowedElsewhere) {
            throw new NotFoundException('Method not allowed for ' . $path);
        }

        throw new NotFoundException('No route for ' . $path);
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /**
     * @param array<string, string|int> $params
     */
    public function path(string $name, array $params = [], ?string $locale = null): string
    {
        if (!isset($this->named[$name])) {
            throw new \InvalidArgumentException('Unknown route: ' . $name);
        }

        $route = $this->named[$name];
        $pattern = $route['pattern'];

        $result = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
            static function (array $m) use (&$params): string {
                $key = $m[1];
                $value = $params[$key] ?? '';
                unset($params[$key]);

                return rawurlencode((string) $value);
            },
            $pattern
        ) ?? $pattern;

        if ($route['localised'] && $locale !== null && $locale !== '') {
            $result = '/' . $locale . ($result === '/' ? '' : $result);
        }

        if ($params !== []) {
            $query = http_build_query($params);
            if ($query !== '') {
                $result .= '?' . $query;
            }
        }

        return $result === '' ? '/' : $result;
    }

    /**
     * @return list<array{method: string, pattern: string, name: string, localised: bool}>
     */
    public function routes(): array
    {
        return array_map(
            static fn (array $r): array => [
                'method' => $r['method'],
                'pattern' => $r['pattern'],
                'name' => $r['name'],
                'localised' => $r['localised'],
            ],
            $this->routes
        );
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static function (array $m): string {
                $name = $m[1];
                $constraint = $m[2] ?? '[^/]+';

                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $pattern
        ) ?? $pattern;

        return '#^' . $regex . '$#u';
    }
}
