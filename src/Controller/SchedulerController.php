<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Scheduler\Scheduler;
use SecondStay\Scheduler\SchedulerFactory;
use SecondStay\Security\RateLimiter;
use SecondStay\Settings\SettingsService;

/**
 * Déclenchement HTTP des tâches périodiques (ARCHITECTURE.md §23).
 *
 * La ligne de commande reste la voie normale — `src/Scheduler/cron.php`, hors
 * de portée du serveur web. Mais une partie des hébergements mutualisés ne
 * propose qu'un cron « par URL » : sans cette porte, ces installations
 * n'auraient aucune tâche périodique, donc pas de relève de courrier, pas de
 * sauvegarde et pas de purge. Une fonction absente est une fonction dont
 * personne ne surveille l'absence.
 *
 * La porte est donc ouverte, mais étroite :
 *
 * - **fermée par défaut.** Sans jeton enregistré, la route répond 404 : elle
 *   n'existe pas plus qu'un chemin inventé, et ne signale pas sa présence ;
 * - **jeton long, comparé en temps constant**, jamais journalisé ;
 * - **limitée en débit par adresse d'appel**, et non par jeton présenté : un
 *   balayage essaie précisément un jeton différent à chaque coup, un compteur
 *   indexé sur le jeton lui ouvrirait donc un compteur neuf à chaque essai. La
 *   table des compteurs ne porte ainsi rien d'autre qu'une adresse IP, jamais
 *   la liste des secrets tentés ;
 * - **muette sur l'état du produit** : la réponse dit ce qui a tourné, jamais
 *   pourquoi un jeton est refusé.
 */
final class SchedulerController extends AbstractController
{
    /** Appels autorisés par fenêtre de limitation. */
    private const MAX_ATTEMPTS = 30;

    /**
     * @param array<string, string> $params
     */
    public function run(RequestContext $context, array $params = []): Response
    {
        $expected = $this->container->get(SettingsService::class)->string('scheduler.http_token');

        if (strlen($expected) < Scheduler::MINIMUM_TOKEN_LENGTH) {
            return $this->closed();
        }

        $presented = $context->request->query('token', '') ?? '';
        if ($presented === '') {
            return $this->closed();
        }

        // Le compteur est indexé sur l'appelant, pas sur le jeton : celui qui
        // balaie change de jeton à chaque essai et se verrait offrir un
        // compteur neuf à chaque fois. L'adresse, elle, ne change pas.
        $bucket = 'scheduler:ip:' . $context->request->ip();
        $limit = $this->container->get(RateLimiter::class)->hit($bucket, self::MAX_ATTEMPTS);

        if (!$limit['allowed']) {
            return Response::text("rate limited\n", 429)
                ->withHeader('Retry-After', (string) $limit['retry_after']);
        }

        if (!hash_equals($expected, $presented)) {
            return $this->closed();
        }

        // Un appel légitime ne doit pas consommer le quota de la fenêtre :
        // sur un hébergement qui appelle l'URL toutes les cinq minutes, le
        // cron finirait par se limiter lui-même. Seuls les essais ratés
        // comptent.
        $this->container->get(RateLimiter::class)->reset($bucket);

        $results = SchedulerFactory::build($this->container)->runDue();

        $body = '';
        $failed = 0;
        foreach ($results as $result) {
            $body .= $result['task'] . ' ' . $result['status'] . "\n";
            if ($result['status'] === 'error') {
                $failed++;
            }
        }

        if ($results === []) {
            $body = "nothing due\n";
        }

        return Response::text($body, $failed > 0 ? 500 : 200);
    }

    /**
     * Jeton absent, trop court ou faux : la route ne se distingue pas d'un
     * chemin qui n'existe pas.
     */
    private function closed(): Response
    {
        return Response::text("not found\n", 404);
    }
}
