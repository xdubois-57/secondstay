<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Auth\AuthService;
use SecondStay\Core\Exception\ForbiddenException;
use SecondStay\Core\Exception\HttpException;
use SecondStay\Core\Http\Request;
use SecondStay\Core\Http\Response;
use SecondStay\Installer\InstallationState;
use SecondStay\Installer\InstallationStatus;
use SecondStay\Logging\Logger;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Security\Csrf;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Locales;
use SecondStay\I18n\LocaleResolver;
use SecondStay\I18n\Translator;
use Throwable;

final class Kernel
{
    private Container $container;

    private bool $booted = false;

    /** @var list<string> préfixes accessibles pendant la maintenance */
    private const MAINTENANCE_ALLOWED_PREFIXES = ['/admin', '/login', '/logout', '/api/health', '/api/version'];

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

        Services::register($container, $this->projectRoot, $this->version());

        $this->container = $container;
        $this->booted = true;

        return $container;
    }

    public function handle(Request $request): Response
    {
        $container = $this->boot();
        $config = $container->get(Config::class);

        try {
            // Défense en profondeur : même si le serveur web est mal configuré,
            // l'application refuse de servir un chemin privé.
            if (PublicPathPolicy::isBlocked($request->path)) {
                throw new ForbiddenException('Blocked path: ' . $request->path);
            }

            $session = $container->get(Session::class);
            $session->start();

            $resolver = $container->get(LocaleResolver::class);
            $extracted = $resolver->extractPrefix($request->path);
            $localePrefixPresent = $extracted['locale'] !== null;
            $routePath = $extracted['path'];

            $installation = $container->get(InstallationState::class);
            $status = $installation->status(Services::optionalDatabase($container));
            $installed = $status->isOperational();

            $accountLocale = null;
            if ($installed) {
                try {
                    $accountLocale = $container->get(AuthService::class)->user()?->locale;
                } catch (Throwable) {
                    $accountLocale = null;
                }
            }

            $locale = $resolver->resolve($request, $extracted['locale'], $accountLocale);

            $translator = $container->get(Translator::class);
            $translator->setLocale($locale);

            $formatter = new Formatter(
                $locale,
                $this->timezone($container, $config, $installed),
                $config->string('app.currency', 'EUR')
            );
            $container->instance(Formatter::class, $formatter);

            $context = new RequestContext($request, $locale, $localePrefixPresent, $routePath);
            $container->instance(RequestContext::class, $context);

            $this->shareViewGlobals($container, $request, $context, $installed);

            $gate = $this->installationGate($container, $context, $status);
            if ($gate instanceof Response) {
                return $this->finalise($gate, $request, $container, $localePrefixPresent, $locale);
            }

            $maintenance = $this->maintenanceGate($container, $context, $installed);
            if ($maintenance instanceof Response) {
                return $this->finalise($maintenance, $request, $container, $localePrefixPresent, $locale);
            }

            $router = $container->get(Router::class);
            $match = $router->match($request->method, $routePath);

            $this->csrfGate($container, $request, $routePath);

            /** @var class-string $controllerClass */
            $controllerClass = $match['handler'][0];
            $method = $match['handler'][1];

            /** @var object $controller */
            $controller = new $controllerClass($container);
            /** @var callable $callable */
            $callable = [$controller, $method];

            /** @var Response $response */
            $response = $callable($context, $match['params']);

            return $this->finalise($response, $request, $container, $localePrefixPresent, $locale);
        } catch (Throwable $throwable) {
            return $this->applySecurityHeaders($this->renderError($throwable, $request), $config);
        }
    }

    private function finalise(
        Response $response,
        Request $request,
        Container $container,
        bool $localePrefixPresent,
        string $locale,
    ): Response {
        $config = $container->get(Config::class);

        if ($localePrefixPresent) {
            $this->persistLocalePreference($response, $request, $locale, $config);
        }

        $session = $container->get(Session::class);
        if ($session instanceof PhpSession) {
            $session->persist();
        }

        return $this->applySecurityHeaders($response, $config);
    }

    private function timezone(Container $container, Config $config, bool $installed): string
    {
        if (!$installed) {
            return $config->string('app.timezone', 'Europe/Paris');
        }

        try {
            $timezone = $container->get(\SecondStay\Settings\SettingsService::class)->string('site.timezone');

            return $timezone !== '' ? $timezone : $config->string('app.timezone', 'Europe/Paris');
        } catch (Throwable) {
            return $config->string('app.timezone', 'Europe/Paris');
        }
    }

    private function shareViewGlobals(
        Container $container,
        Request $request,
        RequestContext $context,
        bool $installed,
    ): void {
        $config = $container->get(Config::class);
        $view = $container->get(View::class);
        $view->setFormatter($container->get(Formatter::class));

        $user = null;
        if ($installed) {
            try {
                $user = $container->get(AuthService::class)->user();
            } catch (Throwable) {
                $user = null;
            }
        }

        $session = $container->get(Session::class);

        $view->share('locale', $context->locale);
        $view->share('locales', Locales::ALL);
        $view->share('base_path', $request->basePath);
        $view->share('app_name', $config->string('app.name', 'SecondStay'));
        $view->share('app_version', $this->version());
        $view->share('current_path', $context->routePath);
        $view->share('request_path', $request->path);
        $view->share('is_installed', $installed);
        $view->share('current_user', $user?->toSafeArray());
        $view->share('is_admin', $user?->isAdministrator() ?? false);
        $view->share('is_operational', $user?->isOperational() ?? false);
        $view->share('csrf_token', $container->get(Csrf::class)->token());
        $view->share('flashes', $session->takeFlashes());

        $menu = [];
        $legal = [];
        if ($installed) {
            try {
                $content = $container->get(\SecondStay\Content\ContentService::class);
                $menu = $content->menuForView($context->locale);
                $legal = $content->legalLinks($context->locale);
            } catch (Throwable) {
                // Contenus indisponibles : la navigation minimale reste servie.
            }
        }
        $view->share('menu_tree', $menu);
        $view->share('legal_links', $legal);
    }

    /**
     * Contrôle d'accès à l'assistant d'installation.
     *
     * - installation jamais faite : tout redirige vers l'assistant ;
     * - installation terminée : l'assistant renvoie 404 ;
     * - installation déjà faite mais instance indisponible (base injoignable,
     *   schéma absent, plus aucun administrateur) : l'assistant reste
     *   inaccessible et le site répond 503. Une panne ne doit jamais permettre
     *   de réinstaller une instance existante (SECURITY.md §5).
     */
    private function installationGate(Container $container, RequestContext $context, InstallationStatus $status): ?Response
    {
        $isInstallRoute = str_starts_with($context->routePath, '/install');
        $isTechnical = str_starts_with($context->routePath, '/api/');

        if ($status === InstallationStatus::NotInstalled) {
            if ($isInstallRoute || $isTechnical) {
                return null;
            }

            return Response::redirect($context->request->basePath . '/' . $context->locale . '/install');
        }

        if ($isInstallRoute) {
            throw new \SecondStay\Core\Exception\NotFoundException('Assistant d’installation indisponible.');
        }

        if ($status === InstallationStatus::Unavailable) {
            if ($isTechnical) {
                return null;
            }

            $container->get(Logger::class)->critical(
                'installation',
                'Instance installée mais indisponible : base injoignable ou schéma incomplet.'
            );

            return $this->unavailableResponse($container);
        }

        return null;
    }

    private function unavailableResponse(Container $container): Response
    {
        $translator = $container->get(Translator::class);
        $view = $container->get(View::class);

        return Response::html(
            $view->render('error/error.html.twig', [
                'status' => 503,
                'title' => $translator->trans('error.503.title'),
                'message' => $translator->trans('error.503.message'),
                'reference' => null,
                'debug_message' => null,
                'debug_trace' => null,
            ]),
            503
        )->withHeader('Retry-After', '120');
    }

    private function maintenanceGate(Container $container, RequestContext $context, bool $installed): ?Response
    {
        if (!$installed) {
            return null;
        }

        $maintenance = $container->get(MaintenanceMode::class);
        $state = $maintenance->state();
        if (!$state['active']) {
            return null;
        }

        foreach (self::MAINTENANCE_ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($context->routePath, $prefix)) {
                return null;
            }
        }

        try {
            if ($container->get(AuthService::class)->isAdministrator()) {
                return null;
            }
        } catch (Throwable) {
            // Base indisponible : on reste en maintenance.
        }

        $translator = $container->get(Translator::class);
        $view = $container->get(View::class);

        return Response::html(
            $view->render('error/error.html.twig', [
                'status' => 503,
                'title' => $translator->trans('error.503.title'),
                'message' => $translator->trans('error.503.message'),
                'reference' => null,
                'debug_message' => null,
                'debug_trace' => null,
            ]),
            503
        )->withHeader('Retry-After', '600');
    }

    /**
     * Toute mutation navigateur nécessite un jeton CSRF valide (SECURITY.md §6).
     */
    private function csrfGate(Container $container, Request $request, string $routePath): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // Les webhooks fournisseurs sont authentifiés par signature, pas par CSRF.
        if (str_starts_with($routePath, '/webhook/')) {
            return;
        }

        $csrf = $container->get(Csrf::class);
        $token = $request->input(Csrf::FIELD) ?? $request->header('X-CSRF-Token');

        if (!$csrf->isValid($token)) {
            $container->get(Logger::class)->warning('security', 'Jeton CSRF invalide', ['path' => $routePath]);

            throw new ForbiddenException('Invalid CSRF token');
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
            // Ne jamais laisser la journalisation casser la réponse d'erreur.
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
