<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Core\Exception\ForbiddenException;
use SecondStay\Core\Exception\HttpException;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Request;
use SecondStay\Core\Http\Response;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Locales;
use SecondStay\I18n\LocaleResolver;
use SecondStay\I18n\Translator;
use Throwable;

final class Kernel
{
    private Container $container;

    private bool $booted = false;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function boot(): Container
    {
        if ($this->booted) {
            return $this->container;
        }

        $container = new Container();
        $config = Config::load($this->projectRoot);

        $storagePath = $config->string('paths.storage');
        if ($storagePath === '') {
            $storagePath = $this->projectRoot . '/storage';
        }

        $paths = new Paths($this->projectRoot, $storagePath);

        date_default_timezone_set($config->string('app.timezone', 'Europe/Paris'));

        $container->instance(Config::class, $config);
        $container->instance(Paths::class, $paths);

        $container->set(Translator::class, static fn (Container $c): Translator => new Translator(
            $c->get(Paths::class)->translations(),
            $c->get(Config::class)->string('i18n.default_locale', Locales::FALLBACK),
            $c->get(Config::class)->string('i18n.fallback_locale', Locales::FALLBACK),
        ));

        $container->set(LocaleResolver::class, static fn (Container $c): LocaleResolver => new LocaleResolver(
            $c->get(Config::class)->string('i18n.default_locale', Locales::FALLBACK),
            $c->get(Config::class)->string('i18n.cookie_name', 'ss_locale'),
        ));

        $container->set(Formatter::class, static fn (Container $c): Formatter => new Formatter(
            $c->get(Translator::class)->locale(),
            $c->get(Config::class)->string('app.timezone', 'Europe/Paris'),
            $c->get(Config::class)->string('app.currency', 'EUR'),
        ));

        $container->set(Router::class, static function (): Router {
            $router = new Router();
            Routes::register($router);

            return $router;
        });

        $container->set(View::class, static function (Container $c): View {
            $config = $c->get(Config::class);
            $paths = $c->get(Paths::class);
            $debug = $config->bool('app.debug');

            return new View(
                $paths->templates(),
                $c->get(Translator::class),
                $c->get(Formatter::class),
                $c->get(Router::class),
                $debug ? null : $paths->storage('cache/twig'),
                $debug,
            );
        });

        $this->container = $container;
        $this->booted = true;

        return $container;
    }

    public function handle(Request $request): Response
    {
        $container = $this->boot();
        $config = $container->get(Config::class);

        try {
            // Defense en profondeur : meme si le serveur web est mal configure,
            // l'application refuse de servir un chemin prive.
            if (PublicPathPolicy::isBlocked($request->path)) {
                throw new ForbiddenException('Blocked path: ' . $request->path);
            }

            $resolver = $container->get(LocaleResolver::class);
            $extracted = $resolver->extractPrefix($request->path);
            $localePrefixPresent = $extracted['locale'] !== null;
            $locale = $resolver->resolve($request, $extracted['locale']);

            $translator = $container->get(Translator::class);
            $translator->setLocale($locale);

            $formatter = new Formatter(
                $locale,
                $config->string('app.timezone', 'Europe/Paris'),
                $config->string('app.currency', 'EUR')
            );
            $container->instance(Formatter::class, $formatter);

            $router = $container->get(Router::class);
            $view = $container->get(View::class);
            $view->setFormatter($formatter);
            $view->share('locale', $locale);
            $view->share('locales', Locales::ALL);
            $view->share('base_path', $request->basePath);
            $view->share('app_name', $config->string('app.name', 'SecondStay'));
            $view->share('app_version', $this->version());
            $view->share('current_path', $extracted['path']);
            $view->share('request_path', $request->path);

            $context = new RequestContext($request, $locale, $localePrefixPresent, $extracted['path']);
            $container->instance(RequestContext::class, $context);

            $match = $router->match($request->method, $extracted['path']);

            /** @var class-string $controllerClass */
            $controllerClass = $match['handler'][0];
            $method = $match['handler'][1];

            /** @var object $controller */
            $controller = new $controllerClass($container);
            /** @var callable $callable */
            $callable = [$controller, $method];

            /** @var Response $response */
            $response = $callable($context, $match['params']);

            if ($localePrefixPresent) {
                $this->persistLocalePreference($response, $request, $locale, $config);
            }

            return $this->applySecurityHeaders($response, $config);
        } catch (Throwable $throwable) {
            return $this->applySecurityHeaders($this->renderError($throwable, $request), $config);
        }
    }

    public function version(): string
    {
        $file = $this->projectRoot . '/VERSION';
        if (!is_file($file)) {
            return '0.0.0';
        }

        $content = file_get_contents($file);

        return $content === false ? '0.0.0' : trim($content);
    }

    /**
     * Persiste la langue explicitement choisie dans un cookie strictement
     * fonctionnel (I18N.md section 6).
     */
    private function persistLocalePreference(Response $response, Request $request, string $locale, Config $config): void
    {
        $cookieName = $config->string('i18n.cookie_name', 'ss_locale');
        if ($request->cookie($cookieName) === $locale) {
            return;
        }

        $response->withCookie($cookieName, $locale, [
            'expires' => time() + ($config->int('i18n.cookie_lifetime_days', 365) * 86400),
            'path' => $request->basePath === '' ? '/' : $request->basePath . '/',
            'secure' => $request->isSecure(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private function applySecurityHeaders(Response $response, Config $config): Response
    {
        $response->withHeader('X-Content-Type-Options', 'nosniff');
        $response->withHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        if (!$config->bool('app.debug')) {
            $response->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; "
                . "font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"
            );
        }

        return $response;
    }

    private function renderError(Throwable $throwable, Request $request): Response
    {
        $container = $this->boot();
        $config = $container->get(Config::class);
        $status = $throwable instanceof HttpException ? $throwable->statusCode() : 500;

        if ($status >= 500) {
            $this->logCritical($throwable);
        }

        $reference = substr(bin2hex(random_bytes(8)), 0, 12);

        $wantsJson = str_starts_with($request->path, '/api/')
            || str_contains($request->header('Accept') ?? '', 'application/json');

        if ($wantsJson) {
            return Response::json([
                'error' => [
                    'status' => $status,
                    'reference' => $reference,
                ],
            ], $status);
        }

        try {
            $view = $container->get(View::class);
            $translator = $container->get(Translator::class);

            $html = $view->render('error/error.html.twig', [
                'status' => $status,
                'title' => $translator->trans('error.' . $status . '.title'),
                'message' => $translator->trans('error.' . $status . '.message'),
                'reference' => $status >= 500 ? $reference : null,
                'debug_message' => $config->bool('app.debug') ? $throwable->getMessage() : null,
                'debug_trace' => $config->bool('app.debug') ? $throwable->getTraceAsString() : null,
            ]);

            return Response::html($html, $status);
        } catch (Throwable) {
            return Response::html(
                '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' . $status
                . '</title></head><body><h1>' . $status . '</h1></body></html>',
                $status
            );
        }
    }

    private function logCritical(Throwable $throwable): void
    {
        try {
            $paths = $this->boot()->get(Paths::class);
            $directory = $paths->storage('logs');
            if (!is_dir($directory)) {
                @mkdir($directory, 0o750, true);
            }
            $line = sprintf(
                "[%s] critical %s: %s in %s:%d\n",
                gmdate('c'),
                $throwable::class,
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine()
            );
            @file_put_contents($directory . '/app.log', $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Ne jamais laisser la journalisation casser la reponse d'erreur.
        }
    }

    public function container(): Container
    {
        return $this->boot();
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }
}
