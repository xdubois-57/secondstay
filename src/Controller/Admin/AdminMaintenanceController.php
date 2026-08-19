<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Maintenance\MaintenanceMode;

final class AdminMaintenanceController extends AdminController
{
    protected function section(): string
    {
        return 'dashboard';
    }

    /**
     * @param array<string, string> $params
     */
    public function toggle(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $maintenance = $this->container->get(MaintenanceMode::class);

        $enable = $context->request->input('enable') === '1';
        $before = $maintenance->isActive();

        if ($enable) {
            $maintenance->enable('maintenance.reason.manual');
            $this->flashWarning('admin.maintenance.enabled');
        } else {
            $maintenance->disable();
            $this->flashSuccess('admin.maintenance.disabled');
        }

        $this->audit()->record(
            'maintenance.toggled',
            'maintenance',
            '',
            ['active' => $before],
            ['active' => $enable],
            $user->id,
            $user->email,
        );

        return $this->redirectToRoute($context, 'admin.dashboard');
    }
}
