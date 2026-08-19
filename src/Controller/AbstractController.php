<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\AuthService;
use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Core\Config;
use SecondStay\Core\Container;
use SecondStay\Core\Exception\ForbiddenException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\Paths;
use SecondStay\Core\RequestContext;
use SecondStay\Core\Router;
use SecondStay\Core\Session;
use SecondStay\Core\View;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Logging\Logger;
use SecondStay\Settings\SettingsService;

abstract class AbstractController
{
    public function __construct(protected readonly Container $container)
    {
    }

    protected function view(): View
    {
        return $this->container->get(View::class);
    }

    protected function translator(): Translator
    {
        return $this->container->get(Translator::class);
    }

    protected function formatter(): Formatter
    {
        return $this->container->get(Formatter::class);
    }

    protected function config(): Config
    {
        return $this->container->get(Config::class);
    }

    protected function paths(): Paths
    {
        return $this->container->get(Paths::class);
    }

    protected function router(): Router
    {
        return $this->container->get(Router::class);
    }

    protected function session(): Session
    {
        return $this->container->get(Session::class);
    }

    protected function auth(): AuthService
    {
        return $this->container->get(AuthService::class);
    }

    protected function settings(): SettingsService
    {
        return $this->container->get(SettingsService::class);
    }

    protected function logger(): Logger
    {
        return $this->container->get(Logger::class);
    }

    protected function audit(): AuditTrail
    {
        return $this->container->get(AuditTrail::class);
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    protected function trans(string $key, array $parameters = []): string
    {
        return $this->translator()->trans($key, $parameters);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function render(string $template, array $context = [], int $status = 200): Response
    {
        return Response::html($this->view()->render($template, $context), $status);
    }

    /**
     * @param array<string, string|int> $params
     */
    protected function redirectToRoute(
        RequestContext $context,
        string $name,
        array $params = [],
        ?string $locale = null,
    ): Response {
        $target = $context->request->basePath
            . $this->router()->path($name, $params, $locale ?? $context->locale);

        return Response::redirect($target);
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * Autorisation serveur systématique (SECURITY.md §5).
     */
    protected function requireRole(Role $role): User
    {
        $user = $this->auth()->user();
        if ($user === null || !$user->role->includes($role)) {
            throw new ForbiddenException('Rôle requis : ' . $role->value);
        }

        return $user;
    }

    protected function requireAdministrator(): User
    {
        return $this->requireRole(Role::Administrator);
    }

    protected function flashSuccess(string $translationKey): void
    {
        $this->session()->flash('success', $translationKey);
    }

    protected function flashError(string $translationKey): void
    {
        $this->session()->flash('danger', $translationKey);
    }

    protected function flashWarning(string $translationKey): void
    {
        $this->session()->flash('warning', $translationKey);
    }
}
