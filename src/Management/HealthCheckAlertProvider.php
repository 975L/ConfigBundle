<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Alerts on what the last health check found, on the dashboard and on the Health check page itself. It doubles as the "it's done" signal for a run queued from the dashboard (see HealthCheckController::run(), which returns as soon as the jobs are dispatched): unlike a flash message, it's still there whenever the admin comes back, and it goes away on its own once nothing is left to fix
class HealthCheckAlertProvider implements AlertProviderInterface
{
    public function __construct(
        private readonly HealthCheckResultRepository $healthCheckResultRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getAlerts(): array
    {
        $results = $this->healthCheckResultRepository->findLatestPerUrlAndKind();

        $errors = $this->count($results, HealthCheckResult::STATUS_ERROR);
        $warnings = $this->count($results, HealthCheckResult::STATUS_WARNING);

        // Nothing checked yet, or nothing left to fix: an alert saying "all good" would be permanent noise on a dashboard whose alerts are meant to be acted upon
        if (0 === $errors && 0 === $warnings) {
            return [];
        }

        // A single alert rather than one per severity: they lead to the same page, where the table already tells the rows apart. Errors decide the severity when there are any, warnings only being worth a look
        return [[
            'label' => $errors > 0
                ? $this->translator->trans('label.health_check_alert_errors', ['%count%' => $errors], 'config')
                : $this->translator->trans('label.health_check_alert_warnings', ['%count%' => $warnings], 'config'),
            'description' => $this->translator->trans(
                'description.health_check_alert',
                ['%date%' => $this->latestCheckedAt($results)->format('d/m/Y H:i')],
                'config',
            ),
            'severity' => $errors > 0 ? Config::SEVERITY_DANGER : Config::SEVERITY_WARNING,
            'url' => $this->urlGenerator->generate('management_health_check_index'),
        ]];
    }

    // @param HealthCheckResult[] $results
    private function count(array $results, string $status): int
    {
        return \count(array_filter($results, static fn (HealthCheckResult $result) => $status === $result->getStatus()));
    }

    // @param non-empty-array<HealthCheckResult> $results - which is what having at least one error or warning guarantees
    private function latestCheckedAt(array $results): \DateTimeInterface
    {
        return max(array_map(static fn (HealthCheckResult $result) => $result->getCheckedAt(), $results));
    }
}
