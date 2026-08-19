<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Update\UpdateService;
use Throwable;

final class AdminUpdateController extends AdminController
{
    protected function section(): string
    {
        return 'updates';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        return $this->renderState($context, null);
    }

    /**
     * Bouton « Vérifier maintenant » (RELEASE.md §12).
     *
     * @param array<string, string> $params
     */
    public function check(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();
        $this->flashSuccess('admin.updates.checked');

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
            $state = $updates->check($this->settings()->string('update.channel') === 'prerelease');
            if ($state['available'] === false || $state['latest'] === null) {
                $this->flashWarning('admin.updates.none_available');

                return $this->redirectToRoute($context, 'admin.updates');
            }

            $result = $updates->install($state['latest'], $user->email, $user->id);

            if ($result['installed']) {
                $this->flashSuccess('admin.updates.installed');
            } elseif ($result['rolled_back']) {
                $this->flashError('admin.updates.rolled_back');
            } else {
                $this->flashError('admin.updates.error');
            }
        } catch (Throwable $throwable) {
            $this->logger()->error('update', 'Mise à jour impossible', ['reason' => $throwable->getMessage()]);
            $this->flashError('admin.updates.error');
        }

        return $this->redirectToRoute($context, 'admin.updates');
    }

    private function renderState(RequestContext $context, ?string $errorKey): Response
    {
        $updates = $this->container->get(UpdateService::class);
        $allowPrerelease = $this->settings()->string('update.channel') === 'prerelease';

        $state = ['available' => false, 'current' => $updates->currentVersion(), 'latest' => null];
        $unreachable = false;

        try {
            $state = $updates->check($allowPrerelease);
        } catch (Throwable) {
            $unreachable = true;
        }

        $latest = $state['latest'];

        return $this->renderAdmin('admin/updates.html.twig', [
            'meta_title' => $this->trans('admin.updates.title'),
            'current_version' => $state['current'],
            'available' => $state['available'],
            'latest' => $latest?->toArray(),
            'notes' => $latest === null ? '' : $latest->notes,
            'unreachable' => $unreachable,
            'channel' => $this->settings()->string('update.channel'),
            'auto_install' => $this->settings()->bool('update.auto_install'),
            'repository' => $this->settings()->string('update.repository'),
            'error_key' => $errorKey,
            'health_ok' => $updates->healthCheck(),
        ]);
    }
}
