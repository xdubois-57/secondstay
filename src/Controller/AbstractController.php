<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Config;
use SecondStay\Core\Container;
use SecondStay\Core\Http\Response;
use SecondStay\Core\Paths;
use SecondStay\Core\RequestContext;
use SecondStay\Core\Router;
use SecondStay\Core\View;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;

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
    protected function redirectToRoute(RequestContext $context, string $name, array $params = [], ?string $locale = null): Response
    {
        $target = $context->request->basePath
            . $this->router()->path($name, $params, $locale ?? $context->locale);

        return Response::redirect($target);
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}
