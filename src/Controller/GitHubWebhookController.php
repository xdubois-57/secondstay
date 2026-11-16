<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Update\DevUpdateQueue;
use SecondStay\Update\GitHubWebhook;
use SecondStay\Update\UpdateService;
use Throwable;

/**
 * Notifications GitHub du canal de développement (ARCHITECTURE.md §30.6.3).
 *
 * Point d'entrée public, hors langue et sans jeton CSRF : GitHub est une
 * machine sans session. Ce qui l'authentifie est la signature HMAC-SHA256 du
 * corps avec le secret partagé `update.webhook_secret`, vérifiée en temps
 * constant **avant** toute lecture du corps.
 *
 * Réponses :
 *
 *  - 403 pour une signature absente ou fausse, ou un secret non configuré —
 *    consigné, puisque c'est soit une erreur de configuration à voir, soit
 *    quelqu'un qui essaie ;
 *  - 202 quand une poussée met un commit en attente d'installation ;
 *  - 200 pour tout le reste, y compris un événement délibérément ignoré :
 *    GitHub réessaie sur toute autre réponse, et il n'y a rien à réessayer.
 *
 * Rien n'est installé ici : la notification arrive avant que la CI ait
 * construit l'archive. Le commit est noté (`DevUpdateQueue`) et la tâche
 * `dev_update` l'installe dès que son archive existe.
 */
final class GitHubWebhookController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function receive(RequestContext $context, array $params = []): Response
    {
        $request = $context->request;
        $settings = $this->settings();

        $secret = '';
        try {
            $secret = $settings->isSecretDefined('update.webhook_secret')
                ? $settings->string('update.webhook_secret')
                : '';
        } catch (Throwable) {
            $secret = '';
        }

        if ($secret === '') {
            $this->logger()->warning('update', 'Notification GitHub reçue sans secret configuré');

            return Response::json(['status' => 'not_configured'], 403);
        }

        if (!GitHubWebhook::isSignatureValid($secret, $request->body, $request->header(GitHubWebhook::SIGNATURE_HEADER))) {
            $this->logger()->warning('security', 'Signature de notification GitHub invalide', [
                'delivery' => (string) $request->header(GitHubWebhook::DELIVERY_HEADER),
            ]);

            return Response::json(['status' => 'invalid_signature'], 403);
        }

        $queue = $this->container->get(DevUpdateQueue::class);
        $event = strtolower(trim((string) $request->header(GitHubWebhook::EVENT_HEADER)));
        $delivery = substr((string) $request->header(GitHubWebhook::DELIVERY_HEADER), 0, 64);

        $outcome = $this->handle($event, $request->json(), $delivery, $queue);
        $queue->recordDelivery($event === '' ? 'unknown' : substr($event, 0, 32), $outcome);

        return Response::json(['status' => $outcome], $outcome === 'queued' ? 202 : 200);
    }

    /**
     * @param array<mixed> $payload
     */
    private function handle(string $event, array $payload, string $delivery, DevUpdateQueue $queue): string
    {
        if ($event === 'ping') {
            return 'pong';
        }

        if ($event !== 'push') {
            return 'ignored_event';
        }

        $settings = $this->settings();
        if (UpdateService::channel($settings->string('update.channel')) !== UpdateService::CHANNEL_DEV) {
            return 'ignored_channel';
        }

        $branch = $settings->string('update.dev_branch');
        $commit = GitHubWebhook::pushedCommit(
            $payload,
            $settings->string('update.repository'),
            $branch === '' ? 'main' : $branch
        );
        if ($commit === null) {
            return 'ignored_push';
        }

        $queue->enqueue($commit, $branch, $delivery);
        $this->logger()->info('update', 'Commit mis en attente d’installation', [
            'commit' => $commit,
            'branch' => $branch,
        ]);

        return 'queued';
    }
}
