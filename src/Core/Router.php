<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Core\Exception\NotFoundException;

final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: array{0: class-string, 1: string}, name: string, localised: bool, access: Access}> */
    private array $routes = [];

    /** @var array<string, array{pattern: string, localised: bool}> */
    private array $named = [];

    /**
     * Niveau d'accès appliqué aux routes déclarées sans le préciser.
     *
     * Il vaut `Public` hors de toute portée, et c'est délibéré : une route
     * ajoutée sans y penser est déclarée publique, donc confrontée par la
     * matrice d'autorisation au comportement le plus permissif possible. Si
     * elle refuse un visiteur, la gate refuse — un oubli devient bruyant au
     * lieu de passer inaperçu.
     */
    private Access $scope = Access::Public;

    /**
     * Déclare un bloc de routes partageant le même niveau d'accès minimal.
     *
     * Annoter cent quatre-vingt-quatre routes une par une produirait une table
     * que plus personne ne relit — et une table de routes illisible est un
     * danger en soi. Les blocs suivent le découpage que ce fichier avait déjà.
     *
     * Une route peut toujours déclarer son propre niveau : le paramètre
     * l'emporte sur la portée.
     *
     * @param callable(self): void $routes
     */
    public function scoped(Access $access, callable $routes): self
    {
        $previous = $this->scope;
        $this->scope = $access;

        try {
            $routes($this);
        } finally {
            // Restauré même si la déclaration lève : une portée qui fuiterait
            // ferait silencieusement hériter tout le reste du fichier d'un
            // niveau d'accès qu'il n'a pas demandé.
            $this->scope = $previous;
        }

        return $this;
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function add(
        string $method,
        string $pattern,
        array $handler,
        string $name,
        bool $localised = true,
        ?Access $access = null,
    ): self {
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
            'access' => $access ?? $this->scope,
        ];
        $this->named[$name] = ['pattern' => $normalised, 'localised' => $localised];

        return $this;
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function get(
        string $pattern,
        array $handler,
        string $name,
        bool $localised = true,
        ?Access $access = null,
    ): self {
        return $this->add('GET', $pattern, $handler, $name, $localised, $access);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function post(
        string $pattern,
        array $handler,
        string $name,
        bool $localised = true,
        ?Access $access = null,
    ): self {
        return $this->add('POST', $pattern, $handler, $name, $localised, $access);
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

        $result = '';
        $offset = 0;
        foreach (self::parsePlaceholders($pattern) as $placeholder) {
            $result .= substr($pattern, $offset, $placeholder['start'] - $offset);
            $value = $params[$placeholder['name']] ?? '';
            unset($params[$placeholder['name']]);
            $result .= rawurlencode((string) $value);
            $offset = $placeholder['start'] + $placeholder['length'];
        }
        $result .= substr($pattern, $offset);

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
     * @return list<array{method: string, pattern: string, name: string, localised: bool, access: Access, handler: array{0: class-string, 1: string}}>
     */
    public function routes(): array
    {
        return array_map(
            static fn (array $r): array => [
                'method' => $r['method'],
                'pattern' => $r['pattern'],
                'name' => $r['name'],
                'localised' => $r['localised'],
                'access' => $r['access'],
                'handler' => $r['handler'],
            ],
            $this->routes
        );
    }

    /**
     * Analyse les paramètres `{nom}` ou `{nom:contrainte}`.
     *
     * Le scan suit la profondeur des accolades : une contrainte peut donc
     * contenir des quantificateurs comme `{2,5}` sans casser le motif.
     *
     * @return list<array{name: string, constraint: string, start: int, length: int}>
     */
    public static function parsePlaceholders(string $pattern): array
    {
        $placeholders = [];
        $length = strlen($pattern);
        $index = 0;

        while ($index < $length) {
            $open = strpos($pattern, '{', $index);
            if ($open === false) {
                break;
            }

            $depth = 1;
            $cursor = $open + 1;
            while ($cursor < $length) {
                if ($pattern[$cursor] === '{') {
                    $depth++;
                } elseif ($pattern[$cursor] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $cursor++;
            }

            if ($depth !== 0) {
                throw new \InvalidArgumentException('Accolade non fermée dans la route : ' . $pattern);
            }

            $inner = substr($pattern, $open + 1, $cursor - $open - 1);
            $separator = strpos($inner, ':');
            $name = $separator === false ? $inner : substr($inner, 0, $separator);
            $constraint = $separator === false ? '[^/]+' : substr($inner, $separator + 1);

            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
                throw new \InvalidArgumentException('Nom de paramètre invalide dans la route : ' . $pattern);
            }

            $placeholders[] = [
                'name' => $name,
                'constraint' => $constraint === '' ? '[^/]+' : $constraint,
                'start' => $open,
                'length' => $cursor - $open + 1,
            ];


            $index = $cursor + 1;
        }

        return $placeholders;
    }

    private function compile(string $pattern): string
    {
        $regex = '';
        $offset = 0;

        foreach (self::parsePlaceholders($pattern) as $placeholder) {
            $regex .= preg_quote(substr($pattern, $offset, $placeholder['start'] - $offset), '#');
            $regex .= '(?P<' . $placeholder['name'] . '>' . $placeholder['constraint'] . ')';
            $offset = $placeholder['start'] + $placeholder['length'];
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        return '#^' . $regex . '$#u';
    }
}
