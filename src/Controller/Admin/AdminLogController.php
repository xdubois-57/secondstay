<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Logging\LogLevel;
use SecondStay\Logging\LogRepository;

final class AdminLogController extends AdminController
{
    private const PAGE_SIZE = 50;

    protected function section(): string
    {
        return 'logs';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        $filters = [
            'level' => (string) ($context->request->query('level') ?? ''),
            'category' => (string) ($context->request->query('category') ?? ''),
            'q' => (string) ($context->request->query('q') ?? ''),
        ];
        $page = max(1, (int) ($context->request->query('page') ?? '1'));

        $logs = $this->container->get(LogRepository::class);
        $result = $logs->page($filters, $page, self::PAGE_SIZE);

        return $this->renderAdmin('admin/logs.html.twig', [
            'meta_title' => $this->trans('admin.logs.title'),
            'entries' => $result['entries'],
            'levels' => array_map(static fn (LogLevel $l): string => $l->value, LogLevel::cases()),
            'categories' => $logs->categories(),
            'filters' => $filters,
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PAGE_SIZE)),
            'total' => $result['total'],
            'retention_days' => $this->settings()->int('logging.retention_days'),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function purge(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $days = $this->settings()->int('logging.retention_days');
        $removed = $this->logger()->purgeOlderThan($days);

        $this->audit()->record(
            'logs.purged',
            'app_log',
            '',
            null,
            ['removed' => $removed, 'retention_days' => $days],
            $user->id,
            $user->email,
        );

        $this->flashSuccess('admin.logs.purged');

        return $this->redirectToRoute($context, 'admin.logs');
    }

    /**
     * @param array<string, string> $params
     */
    public function auditTrail(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        $page = max(1, (int) ($context->request->query('page') ?? '1'));
        $audit = $this->container->get(\SecondStay\Audit\AuditTrail::class);
        $total = $audit->count();

        return $this->renderAdmin('admin/audit.html.twig', [
            'meta_title' => $this->trans('admin.audit.title'),
            'events' => $audit->recent(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'total' => $total,
            'admin_section' => 'audit',
        ]);
    }
}
