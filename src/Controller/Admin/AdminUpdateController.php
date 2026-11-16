<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Update\DevUpdateQueue;
use SecondStay\Update\GitHubWebhook;
use SecondStay\Update\UpdateService;
use Throwable;

final class AdminUpdateController extends AdminController
{
    protected function section(): string
    {
        return 'updates';
    }

    /**
     * Affiche le dernier résultat mémorisé : ouvrir la page ne coûte un appel
     * à GitHub que si ce résultat est périmé.
     *
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        $updates = $this->container->get(UpdateService::class);
        $recovered = $updates->hasInterruptedUpdate() && $updates->recoverInterrupted();
        if ($recovered) {
            $this->flashWarning('admin.updates.recovered');

            return $this->redirectToRoute($context, 'admin.updates');
        }

        return $this->renderState($context);
    }

    /**
     * Bouton « Vérifier maintenant » (RELEASE.md §12) : interroge la source
     * même si un résultat récent est mémorisé.
     *
     * @param array<string, string> $params
     */
    public function check(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();
        $updates = $this->container->get(UpdateService::class);

        try {
            $state = $updates->refresh($this->channel());
            $this->flashSuccess($state['available'] ? 'admin.updates.found' : 'admin.updates.checked');
        } catch (Throwable $throwable) {
            $this->logger()->warning('update', 'Vérification impossible', ['reason' => $throwable->getMessage()]);
            $this->flashWarning('admin.updates.unreachable');
        }

        return $this->redirectToRoute($context, 'admin.updates');
    }

    /**
     * @param array<string, string> $params
     */
    public function install(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $updates = $this->container->get(UpdateService::class);

        try {
            // L'URL et l'empreinte de l'archive sont relues à la source au
            // moment d'installer, jamais prises dans le cache.
            $state = $updates->refresh($this->channel());
            if ($state['available'] === false || $state['latest'] === null) {
                $this->flashWarning('admin.updates.none_available');

                return $this->redirectToRoute($context, 'admin.updates');
            }

            $result = $updates->install($state['latest'], $user->email, $user->id);

            if ($result['installed']) {
                $this->flashSuccess('admin.updates.installed');
            } else {
                $this->flashError('admin.updates.rolled_back');
            }
        } catch (Throwable $throwable) {
            $this->logger()->error('update', 'Mise à jour impossible', ['reason' => $throwable->getMessage()]);
            $this->flashError($this->errorKey($throwable->getMessage()));
        }

        return $this->redirectToRoute($context, 'admin.updates');
    }

    /**
     * Les deux refus d'empreinte méritent leur propre message : « la mise à
     * jour a échoué » n'envoie chercher nulle part.
     */
    private function errorKey(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'update.error.digest_missing') => 'update.error.digest_missing',
            str_contains($reason, 'update.error.digest_mismatch') => 'update.error.digest_mismatch',
            default => 'admin.updates.error',
        };
    }

    /**
     * « Générer un secret » du canal de développement.
     *
     * Le secret n'est montré **qu'une fois**, dans la réponse même : il est
     * enregistré chiffré (`update.webhook_secret`) et ne se relit jamais en
     * clair ensuite. La page est rendue plutôt que redirigée, et la réponse
     * n'est pas mise en cache, pour que cette unique lecture ne laisse pas de
     * copie derrière elle.
     *
     * @param array<string, string> $params
     */
    public function webhookSecret(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $secret = GitHubWebhook::generateSecret();
        $this->settings()->set('update.webhook_secret', $secret, $user->email, $user->id);

        return $this->renderState($context, $secret)->withHeader('Cache-Control', 'no-store');
    }

    private function channel(): string
    {
        return UpdateService::channel($this->settings()->string('update.channel'));
    }

    private function renderState(RequestContext $context, ?string $generatedSecret = null): Response
    {
        $updates = $this->container->get(UpdateService::class);
        $channel = $this->channel();

        $state = ['available' => false, 'current' => $updates->currentVersion(), 'latest' => null, 'checked_at' => 0];
        $unreachable = false;

        try {
            $state = $updates->state($channel);
        } catch (Throwable) {
            $unreachable = true;
        }

        $latest = $state['latest'];
        $queue = $this->container->get(DevUpdateQueue::class);
        $pending = $queue->pending();
        $delivery = $queue->lastDelivery();

        return $this->renderAdmin('admin/updates.html.twig', [
            'meta_title' => $this->trans('admin.updates.title'),
            'current_version' => $state['current'],
            'available' => $state['available'],
            'latest' => $latest?->toArray(),
            'notes' => $latest === null ? '' : $latest->notes,
            'unreachable' => $unreachable,
            'checked_at' => $state['checked_at'] > 0 ? new \DateTimeImmutable('@' . $state['checked_at']) : null,
            'channel' => $channel,
            'dev_branch' => $this->settings()->string('update.dev_branch'),
            'webhook_url' => rtrim($context->request->baseUrl(), '/') . $this->router()->path('github.webhook'),
            'webhook_secret_defined' => $this->settings()->isSecretDefined('update.webhook_secret'),
            'generated_secret' => $generatedSecret,
            'dev_pending' => $pending === null ? null : [
                'commit' => substr($pending['commit'], 0, 7),
                'received_at' => new \DateTimeImmutable('@' . $pending['received_at']),
            ],
            'last_delivery' => $delivery === null ? null : [
                'event' => $delivery['event'],
                'outcome' => $delivery['outcome'],
                'received_at' => new \DateTimeImmutable('@' . $delivery['received_at']),
            ],
            'auto_install' => $this->settings()->bool('update.auto_install'),
            'repository' => $this->settings()->string('update.repository'),
            'health_ok' => $updates->healthCheck(),
        ]);
    }
}
