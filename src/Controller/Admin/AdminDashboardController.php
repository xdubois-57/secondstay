<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Auth\UserRepository;
use SecondStay\Backup\BackupService;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Database\Migrator;
use SecondStay\Diagnostics\DiagnosticRunner;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Update\UpdateService;
use Throwable;

final class AdminDashboardController extends AdminController
{
    protected function section(): string
    {
        return 'dashboard';
    }

    /**
     * Tableau « À faire » : uniquement ce qui nécessite une action.
     *
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $diagnostics = $this->container->get(DiagnosticRunner::class)->summary();
        $migrator = $this->container->get(Migrator::class);
        $backups = $this->container->get(BackupService::class);
        $maintenance = $this->container->get(MaintenanceMode::class);

        $backupList = $backups->list();
        $todo = [];

        if ($diagnostics['error'] > 0) {
            $todo[] = ['key' => 'admin.todo.diagnostics_error', 'count' => $diagnostics['error'], 'route' => 'admin.diagnostics'];
        }
        if ($migrator->pending() !== []) {
            $todo[] = ['key' => 'admin.todo.pending_migrations', 'count' => count($migrator->pending()), 'route' => 'admin.diagnostics'];
        }
        if ($backupList === []) {
            $todo[] = ['key' => 'admin.todo.no_backup', 'count' => 0, 'route' => 'admin.backups'];
        }
        if ($maintenance->isActive()) {
            $todo[] = ['key' => 'admin.todo.maintenance_active', 'count' => 0, 'route' => 'admin.dashboard'];
        }
        if ($this->settings()->string('property.name') === '') {
            $todo[] = ['key' => 'admin.todo.property_name', 'count' => 0, 'route' => 'admin.settings'];
        }

        $updateState = ['available' => false, 'current' => $this->version(), 'latest' => null];
        try {
            $updateState = $this->container->get(UpdateService::class)->check(
                $this->settings()->string('update.channel') === 'prerelease'
            );
        } catch (Throwable) {
            // Réseau indisponible : le tableau de bord reste utilisable.
        }

        if ($updateState['available']) {
            $todo[] = ['key' => 'admin.todo.update_available', 'count' => 0, 'route' => 'admin.updates'];
        }

        return $this->renderAdmin('admin/dashboard.html.twig', [
            'meta_title' => $this->trans('admin.dashboard.title'),
            'todo' => $todo,
            'diagnostics' => $diagnostics,
            'user_count' => count($this->container->get(UserRepository::class)->all()),
            'administrator_count' => $this->container->get(UserRepository::class)->countAdministrators(),
            'backup_count' => count($backupList),
            'last_backup' => $backupList[0] ?? null,
            'maintenance_active' => $maintenance->isActive(),
            'schema_version' => $migrator->currentVersion(),
            'update_state' => [
                'available' => $updateState['available'],
                'current' => $updateState['current'],
                'latest' => $updateState['latest']?->version,
            ],
            'current_user_name' => $user->displayName(),
        ]);
    }

    private function version(): string
    {
        $file = $this->paths()->root('VERSION');
        $content = is_file($file) ? file_get_contents($file) : false;

        return $content === false ? '0.0.0' : trim($content);
    }
}
