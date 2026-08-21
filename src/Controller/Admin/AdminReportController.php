<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Quota\QuotaService;
use SecondStay\Reporting\ReportPeriod;
use SecondStay\Reporting\ReportService;

/**
 * Reporting et quotas (SPECIFICATIONS.md §66 et §67).
 *
 * L'écran compte ; il ne conseille pas. L'avertissement est affiché, et il
 * voyage aussi dans le classeur exporté.
 */
final class AdminReportController extends AdminController
{
    protected function section(): string
    {
        return 'reports';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $reports = $this->container->get(ReportService::class);
        $period = $this->period($context);

        return $this->renderAdmin('admin/reports.html.twig', [
            'meta_title' => $this->trans('report.title'),
            'report' => $reports->build($period),
            'period' => $period,
            'years' => $reports->years(),
            'selected_year' => (int) ($context->request->query('year') ?? gmdate('Y')),
            'selected_month' => (int) ($context->request->query('month') ?? 0),
            'quotas' => $this->container->get(QuotaService::class)->usage(),
        ]);
    }

    /**
     * Classeur comptable de la période affichée.
     *
     * @param array<string, string> $params
     */
    public function export(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $reports = $this->container->get(ReportService::class);
        $report = $reports->build($this->period($context));

        return new Response($reports->workbook($report, $context->locale), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $reports->filename($report) . '"',
            // Un état comptable n'a rien à faire dans un cache partagé.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Période demandée : un mois si un mois est choisi, l'année sinon.
     */
    private function period(RequestContext $context): ReportPeriod
    {
        $year = (int) ($context->request->query('year') ?? gmdate('Y'));
        $month = (int) ($context->request->query('month') ?? 0);

        return $month >= 1 && $month <= 12
            ? ReportPeriod::month($year, $month)
            : ReportPeriod::year($year);
    }
}
