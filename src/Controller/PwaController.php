<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;
use SecondStay\Pwa\IconGenerator;
use SecondStay\Pwa\ManifestBuilder;
use Throwable;

/**
 * Application installable : manifeste localisé, service worker et icônes
 * générées par l'installation (SPECIFICATIONS.md §43).
 */
final class PwaController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function manifest(RequestContext $context, array $params = []): Response
    {
        $locale = Locales::normalise((string) ($context->request->query('locale') ?? '')) ?? $context->locale;

        return new Response(
            $this->container->get(ManifestBuilder::class)->toJson($locale),
            200,
            [
                'Content-Type' => 'application/manifest+json; charset=UTF-8',
                // Le manifeste change avec les réglages : cache court.
                'Cache-Control' => 'public, max-age=300',
                'Vary' => 'Accept-Language',
            ]
        );
    }

    /**
     * Le service worker doit être servi depuis la racine de son périmètre.
     *
     * @param array<string, string> $params
     */
    public function serviceWorker(RequestContext $context, array $params = []): Response
    {
        $body = $this->container->get(\SecondStay\Core\View::class)->render('pwa/sw.js.twig', [
            'locales' => Locales::ALL,
            'app_name' => $this->settings()->string('property.name') !== ''
                ? $this->settings()->string('property.name')
                : 'SecondStay',
        ]);

        return new Response($body, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => ($context->request->basePath ?: '') . '/',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function icon(RequestContext $context, array $params = []): Response
    {
        $size = (int) ($params['size'] ?? 0);
        $maskable = ($params['maskable'] ?? '') !== '';

        $label = $this->settings()->string('property.name');

        try {
            $file = $this->container->get(IconGenerator::class)->icon($label, $size, $maskable);
        } catch (Throwable) {
            throw new NotFoundException('Icône indisponible.');
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new NotFoundException('Icône indisponible.');
        }

        return new Response($contents, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Page de repli hors ligne, servie depuis le cache du service worker.
     *
     * @param array<string, string> $params
     */
    public function offline(RequestContext $context, array $params = []): Response
    {
        return $this->render('public/offline.html.twig', [
            'meta_title' => $this->trans('pwa.offline.title'),
        ]);
    }
}
