<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Database\Migrator;
use SecondStay\Diagnostics\DiagnosticRunner;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Push\VapidKeyManager;
use SecondStay\Security\RateLimiter;

final class AdminDiagnosticsController extends AdminController
{
    protected function section(): string
    {
        return 'diagnostics';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        $runner = $this->container->get(DiagnosticRunner::class);
        $migrator = $this->container->get(Migrator::class);

        $grouped = [];
        foreach ($runner->run() as $result) {
            $grouped[$result->category][] = [
                'id' => $result->id,
                'status' => $result->status->value,
                'badge' => $result->status->badgeClass(),
                'message' => $this->trans($result->messageKey),
                'details' => $result->details,
            ];
        }

        return $this->renderAdmin('admin/diagnostics.html.twig', [
            'meta_title' => $this->trans('admin.diagnostics.title'),
            'groups' => $grouped,
            'summary' => $runner->summary(),
            'push_keys_present' => $this->container->get(VapidKeyManager::class)->hasKeys(),
            'migrations' => [
                'current' => $migrator->currentVersion(),
                'pending' => array_map(
                    static fn (array $m): string => $m['version'] . '_' . $m['name'],
                    $migrator->pending()
                ),
                'drift' => $migrator->drift(),
            ],
        ]);
    }

    /**
     * Débloque les compteurs de limitation de débit.
     *
     * Un propriétaire qui s'est verrouillé lui-même (tentatives de connexion,
     * inscriptions répétées depuis la même adresse) doit pouvoir repartir sans
     * attendre la fin de la fenêtre. L'action est tracée.
     *
     * @param array<string, string> $params
     */
    public function clearRateLimits(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $removed = $this->container->get(RateLimiter::class)->clearAll();

        $this->audit()->record(
            'security.rate_limits_cleared',
            'rate_limit',
            '',
            null,
            ['removed' => $removed],
            $user->id,
            $user->email,
        );

        $this->flashSuccess('admin.diagnostics.rate_limits_cleared');

        return $this->redirectToRoute($context, 'admin.diagnostics');
    }

    /**
     * Génère (ou renouvelle) la paire de clés VAPID de l'installation.
     *
     * Renouveler invalide tous les abonnements existants : les appareils
     * devront se réabonner. L'action est donc explicite et tracée.
     *
     * @param array<string, string> $params
     */
    public function generatePushKeys(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $manager = $this->container->get(VapidKeyManager::class);
        $renewed = $manager->hasKeys();

        if ($renewed) {
            // Les anciens abonnements ne peuvent plus être déchiffrés.
            $removed = $this->container->get(PushSubscriptionRepository::class)->clearAll();
        } else {
            $removed = 0;
        }

        $manager->ensureKeys(true, $user->email);

        $this->audit()->record(
            'push.keys_generated',
            'setting',
            VapidKeyManager::PUBLIC_SETTING,
            null,
            ['renewed' => $renewed, 'subscriptions_removed' => $removed],
            $user->id,
            $user->email,
        );

        $this->flashSuccess($renewed ? 'admin.diagnostics.push_keys_renewed' : 'admin.diagnostics.push_keys_created');

        return $this->redirectToRoute($context, 'admin.diagnostics');
    }
}
