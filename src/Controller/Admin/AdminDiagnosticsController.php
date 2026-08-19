<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Database\Migrator;
use SecondStay\Diagnostics\DiagnosticRunner;

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
}
