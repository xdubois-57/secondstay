<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Database\Database;
use SecondStay\Logging\LogLevel;

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
        $database = $this->container->get(Database::class);

        $level = (string) ($context->request->query('level') ?? '');
        $category = (string) ($context->request->query('category') ?? '');
        $search = (string) ($context->request->query('q') ?? '');
        $page = max(1, (int) ($context->request->query('page') ?? '1'));

        $conditions = [];
        $parameters = [];

        if ($level !== '' && LogLevel::tryFrom($level) !== null) {
            $conditions[] = '`level` = :level';
            $parameters['level'] = $level;
        }
        if ($category !== '') {
            $conditions[] = '`category` = :category';
            $parameters['category'] = $category;
        }
        if ($search !== '') {
            $conditions[] = '`message` LIKE :search';
            $parameters['search'] = '%' . $search . '%';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $total = (int) $database->fetchValue('SELECT COUNT(*) FROM `app_log`' . $where, $parameters);
        $offset = ($page - 1) * self::PAGE_SIZE;

        $entries = $database->fetchAll(
            'SELECT * FROM `app_log`' . $where . sprintf(' ORDER BY `id` DESC LIMIT %d OFFSET %d', self::PAGE_SIZE, $offset),
            $parameters
        );

        /** @var list<array<string, mixed>> $categories */
        $categories = $database->fetchAll('SELECT DISTINCT `category` FROM `app_log` ORDER BY `category`');

        return $this->renderAdmin('admin/logs.html.twig', [
            'meta_title' => $this->trans('admin.logs.title'),
            'entries' => $entries,
            'levels' => array_map(static fn (LogLevel $l): string => $l->value, LogLevel::cases()),
            'categories' => array_map(static fn (array $row): string => (string) $row['category'], $categories),
            'filters' => ['level' => $level, 'category' => $category, 'q' => $search],
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'total' => $total,
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
