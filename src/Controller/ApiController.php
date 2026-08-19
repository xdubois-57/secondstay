<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Http\Response;
use SecondStay\Core\Paths;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;

final class ApiController extends AbstractController
{
    /**
     * Endpoint public minimal utilisé par la gate de déploiement de release.
     *
     * @param array<string, string> $params
     */
    public function version(RequestContext $context, array $params = []): Response
    {
        return Response::json([
            'name' => $this->config()->string('app.name', 'SecondStay'),
            'version' => $this->appVersion(),
            'locales' => Locales::ALL,
            'default_locale' => $this->config()->string('i18n.default_locale', Locales::FALLBACK),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function health(RequestContext $context, array $params = []): Response
    {
        return Response::json([
            'status' => 'ok',
            'version' => $this->appVersion(),
            'time' => gmdate('c'),
        ]);
    }

    private function appVersion(): string
    {
        $file = $this->container->get(Paths::class)->root('VERSION');
        if (!is_file($file)) {
            return '0.0.0';
        }

        $content = file_get_contents($file);

        return $content === false ? '0.0.0' : trim($content);
    }
}
