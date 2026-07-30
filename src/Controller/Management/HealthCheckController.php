<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Command\HealthCheckRunCommand;
use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\AlertBuilder;
use c975L\ConfigBundle\Management\BackupResultRecorder;
use c975L\ConfigBundle\Management\HealthCheckAdviceBuilder;
use c975L\ConfigBundle\Management\HealthCheckRunner;
use c975L\ConfigBundle\Management\HealthCheckTrendChartBuilder;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class HealthCheckController extends AbstractController
{
    // EasyAdmin prefixes this with the Dashboard's own route name, giving management_health_check_run
    public const RUN_ROUTE = 'management_health_check_run';

    // Kinds checked once for the whole site (infrastructure-level: TLS cert, security headers, robots.txt/sitemap, redirect chains, http/https + 404 deployment checks, last backup) rather than once per page - shown in their own "Site" section instead of the per-page table, see index()
    private const SITE_WIDE_KINDS = ['security-headers', 'ssl-certificate', 'seo-files', 'redirect-chains', 'deployment', BackupResultRecorder::KIND];

    public function __construct(
        private readonly HealthCheckResultRepository $healthCheckResultRepository,
        private readonly HealthCheckRunner $healthCheckRunner,
        private readonly AlertBuilder $alertBuilder,
        private readonly HealthCheckAdviceBuilder $healthCheckAdviceBuilder,
        private readonly TableExporter $tableExporter,
        private readonly HealthCheckTrendChartBuilder $healthCheckTrendChartBuilder,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    // Custom admin page (not tied to any entity), registered under the Dashboard's own route path/name, giving /management/health-check and management_health_check_index. Reads the latest persisted results only - a GET here never triggers a live check (see run() below and HealthCheckRunner, also run periodically from c975l:health-check:run)
    #[AdminRoute(path: '/health-check', name: 'health_check_index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        $results = $this->healthCheckResultRepository->findLatestPerUrlAndKind();

        // Site-wide kinds (see SITE_WIDE_KINDS) are checked once for the whole site, never per-page like the rest - mixed into the same table they'd read as one page among many instead of the site-wide result they actually are, so they're pulled out into their own "Site" section instead
        $siteResults = [];
        $pageResults = [];
        foreach ($results as $result) {
            if (\in_array($result->getKind(), self::SITE_WIDE_KINDS, true)) {
                $siteResults[] = $result;
                continue;
            }
            $pageResults[] = $result;
        }

        return $this->render(
            '@c975LConfig/management/health_check/index.html.twig',
            [
                'results' => $pageResults,
                // Distinct kinds across the current page results, for the table's "kind" filter dropdown - computed here rather than in Twig, which has no built-in "unique" filter
                'kinds' => array_values(array_unique(array_map(static fn (HealthCheckResult $result) => $result->getKind(), $pageResults))),
                'siteResults' => $siteResults,
                'siteKinds' => array_values(array_unique(array_map(static fn (HealthCheckResult $result) => $result->getKind(), $siteResults))),
                // Same dashboard-wide list as management/index.html.twig (not filtered to health-check-specific alerts, there's no such category today) - so a config a HealthCheckProvider depends on (e.g. healthcheck-pagespeed-api-key) is visible here too, not just on the dashboard
                'alerts' => $this->alertBuilder->getAlerts(),
                'trendChart' => $this->healthCheckTrendChartBuilder->build(),
                // Every page is checked in the same run (see HealthCheckRunner::run()), so a per-row date is redundant - shown once above the table instead, taking the most recent in case a kind was re-run on its own. The backup rows are left out: they land here every few hours on a scheduler of their own, and would keep this date fresh long after the health-check one had stopped running - the very failure it's here to make visible
                'lastCheckedAt' => $this->latestCheckedAt(array_filter(
                    $results,
                    static fn (HealthCheckResult $result) => BackupResultRecorder::KIND !== $result->getKind()
                )),
                // Built once across every result (site + page) and handed to both table includes below - the same shared table (health_check/_table.html.twig) any CRUD's own "Health check" tab uses, so advice reads identically everywhere
                'advice' => $this->healthCheckAdviceBuilder->build($results),
            ]
        );
    }

    // Queues the run instead of executing it, one job per provider kind: a single provider can hold thousands of urls (a gallery declares one per photo), which no admin request can wait for without timing out - and a run that times out persists nothing at all. The jobs are the very console command the scheduler already runs, so nothing new has to be wired beyond routing RunCommandMessage to an async transport (see the readme). Results land on this page as each job completes, and HealthCheckAlertProvider raises what needs attention on the dashboard
    #[AdminRoute(
        path: '/health-check/run',
        name: 'health_check_run',
        options: ['methods' => ['POST']]
    )]
    public function run(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        if ($this->isCsrfTokenValid(self::RUN_ROUTE, $request->request->get('_token'))) {
            $kinds = $this->healthCheckRunner->getKinds();

            foreach ($kinds as $kind) {
                $this->messageBus->dispatch(new RunCommandMessage(HealthCheckRunCommand::NAME . ' --kind=' . $kind));
            }

            $this->addFlash('success', $this->translator->trans(
                'flash.health_check_queued',
                ['%count%' => \count($kinds)],
                'config',
            ));
        } else {
            $this->addFlash('danger', $this->translator->trans('flash.health_check_run_invalid_token', [], 'config'));
        }

        return $this->redirectToRoute('management_health_check_index');
    }

    // Dated CSV snapshot of the current results (one row per url/kind, see HealthCheckResultRepository::findLatestPerUrlAndKind()) - the audit-trail artefact for accessibility declarations (RGAA/EAA): each row already carries its own checkedAt, and TableExporter dates the filename itself, so re-exporting weekly/monthly builds a paper trail without any extra bookkeeping here. Unlike index(), site-wide kinds (see SITE_WIDE_KINDS) are deliberately kept in the export rather than split out - completeness matters more than the dashboard's readability concern here, and the 'kind' column already discloses which rows are site-wide
    #[AdminRoute(path: '/health-check/export', name: 'health_check_export')]
    public function exportCsv(): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        $rows = array_map(
            static fn (HealthCheckResult $result) => [
                'kind' => $result->getKind(),
                'url' => $result->getUrl(),
                'label' => $result->getLabel(),
                'status' => $result->getStatus(),
                'summary' => $result->getSummary(),
                'checkedAt' => $result->getCheckedAt()->format('Y-m-d H:i:s'),
            ],
            $this->healthCheckResultRepository->findLatestPerUrlAndKind(),
        );

        return $this->tableExporter->export(ExportFormat::Csv, 'health_check', $rows);
    }

    // @param HealthCheckResult[] $results
    private function latestCheckedAt(array $results): ?\DateTimeInterface
    {
        if ([] === $results) {
            return null;
        }

        return max(array_map(static fn (HealthCheckResult $result) => $result->getCheckedAt(), $results));
    }
}
