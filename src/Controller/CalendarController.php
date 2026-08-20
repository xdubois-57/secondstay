<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Calendar\CalendarService;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;

/**
 * Publication des flux ICS privés (SPECIFICATIONS.md §51).
 *
 * L'accès repose entièrement sur le jeton : un agenda tiers ne présentera
 * jamais de session. Le jeton est donc long, unique et révocable, et un jeton
 * inconnu ou révoqué se présente comme une adresse qui n'existe pas — sans
 * dire laquelle des deux, ce qui n'apprendrait rien d'utile à personne.
 */
final class CalendarController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function feed(RequestContext $context, array $params = []): Response
    {
        if (!$this->settings()->bool('operations.calendar_enabled')) {
            throw new NotFoundException('Calendriers désactivés.');
        }

        $feed = $this->container->get(CalendarService::class)->feedFor((string) ($params['token'] ?? ''));

        if ($feed === null) {
            throw new NotFoundException('Calendrier introuvable.');
        }

        return new Response($feed, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="secondstay.ics"',
            // Un calendrier privé n'a rien à faire dans un cache partagé, et
            // un agenda doit voir les changements sans délai.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // Aucun robot ne doit indexer une adresse porteuse d'un jeton.
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
