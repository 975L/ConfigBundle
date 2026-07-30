<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

// Builds the "is our site's health improving or degrading" line chart for the Health check page, from HealthCheckResultRepository::findStatusCountsByDate() - the historisation artefact (RGAA/EAA audit trail), not a per-page breakdown
class HealthCheckTrendChartBuilder
{
    private const COLORS = [
        HealthCheckResult::STATUS_OK => '#198754',
        HealthCheckResult::STATUS_WARNING => '#ffc107',
        HealthCheckResult::STATUS_ERROR => '#dc3545',
    ];

    public function __construct(
        private readonly HealthCheckResultRepository $healthCheckResultRepository,
        private readonly ChartBuilderInterface $chartBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Null when there's no history yet (first run never happened) - the template skips render_chart() entirely in that case
    public function build(): ?Chart
    {
        $trend = $this->healthCheckResultRepository->findStatusCountsByDate();
        if (!$trend['dates']) {
            return null;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $trend['dates'],
            'datasets' => array_map(
                fn (string $status, array $counts) => [
                    'label' => $this->translator->trans('label.health_check_status_' . $status, [], 'config'),
                    'data' => $counts,
                    'borderColor' => self::COLORS[$status],
                    'backgroundColor' => self::COLORS[$status],
                    'tension' => 0.2,
                ],
                array_keys($trend['series']),
                array_values($trend['series']),
            ),
        ]);
        $chart->setOptions(['scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['stepSize' => 1]]]]);

        return $chart;
    }
}
