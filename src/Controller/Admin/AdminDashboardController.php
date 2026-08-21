<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Auth\UserRepository;
use SecondStay\Backup\BackupService;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Operations\TodoService;
use SecondStay\Database\Migrator;
use SecondStay\Diagnostics\DiagnosticRunner;
use SecondStay\Maintenance\MaintenanceMode;

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
        $maintenance = $this->container->get(MaintenanceMode::class);

        $backupList = $this->container->get(BackupService::class)->list();

        // Le tableau « À faire » vient d'un seul service, sur les deux écrans
        // qui l'affichent (SPECIFICATIONS.md §50). Le tableau de bord n'y
        // ajoute qu'une entrée : le nombre de diagnostics en erreur, qu'il est
        // le seul à connaître puisqu'il calcule déjà ce résumé pour ses
        // indicateurs. Tout le reste — sauvegarde absente, mise à jour
        // disponible, migration en attente — était autrefois recalculé ici,
        // avec ses propres libellés et une définition qui divergeait de celle
        // de l'écran d'exploitation.
        $todo = [];

        if ($diagnostics['error'] > 0) {
            $todo[] = [
                'code' => 'diagnostics_error',
                'key' => 'admin.todo.diagnostics_error',
                'severity' => 'danger',
                'count' => $diagnostics['error'],
                'route' => 'admin.diagnostics',
                'params' => [],
            ];
        }

        foreach ($this->container->get(TodoService::class)->items() as $operational) {
            $todo[] = $operational;
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
            'current_user_name' => $user->displayName(),
        ]);
    }
}
