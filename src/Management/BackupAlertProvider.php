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
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Alerts on the state of the last backup, read live at every dashboard load. This is the half no report email can cover: an email only exists when the command ran far enough to send one, so a scheduler consumer that died, a crontab lost on a server move or a PHP fatal in the middle of a dump all produce the exact same signal - nothing at all - and the absence of a mail is precisely what nobody notices. Here, a run that never happened is what raises the loudest alert
class BackupAlertProvider implements AlertProviderInterface
{
    // Backups run every 6 hours in the schedule the bundles document, so a full day and a quarter without one means something stopped rather than merely ran late. Public because BackupDigestBuilder falls back on the very same threshold: the dashboard alert and the weekly email disagreeing on what "late" means is how one of them ends up ignored
    public const DEFAULT_MAX_AGE_HOURS = 30;

    public function __construct(
        private readonly HealthCheckResultRepository $healthCheckResultRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getAlerts(): array
    {
        // Nothing is configured yet, so nothing is expected to run: the empty site-backup-database entry is itself declared with a "danger" severity, and ConfigAlertProvider already alerts on it. Alerting here too would just say the same thing twice
        if ('' === (string) $this->configService->get('site-backup-database')) {
            return [];
        }

        $latest = $this->healthCheckResultRepository->findLatestByKind(BackupResultRecorder::KIND, 1)[0] ?? null;

        $alert = null === $latest ? $this->neverRanAlert() : $this->latestRunAlert($latest);

        return null === $alert ? [] : [$alert];
    }

    // A configured backup that has never recorded a run: either it has genuinely never run, or every run so far predates this bundle version - both are worth one look, neither is worth a danger
    private function neverRanAlert(): array
    {
        return $this->alert('label.backup_alert_never_ran', [], Config::SEVERITY_WARNING);
    }

    private function latestRunAlert(HealthCheckResult $latest): ?array
    {
        $hours = $this->hoursSince($latest->getCheckedAt());

        // Staleness first, and whatever the last run's own status was: a backup that succeeded a fortnight ago is a worse problem than one that failed this morning, and the successful row would otherwise keep the dashboard quiet
        if ($hours > $this->maxAgeHours()) {
            return $this->alert('label.backup_alert_stale', ['%hours%' => $hours], Config::SEVERITY_DANGER, $latest);
        }

        return match ($latest->getStatus()) {
            HealthCheckResult::STATUS_ERROR => $this->alert('label.backup_alert_error', [], Config::SEVERITY_DANGER, $latest),
            HealthCheckResult::STATUS_WARNING => $this->alert('label.backup_alert_warning', [], Config::SEVERITY_WARNING, $latest),
            default => null,
        };
    }

    private function alert(string $labelId, array $parameters, string $severity, ?HealthCheckResult $latest = null): array
    {
        return [
            'label' => $this->translator->trans($labelId, $parameters, 'config'),
            'description' => null === $latest
                ? $this->translator->trans('description.backup_alert_never_ran', [], 'config')
                : $this->translator->trans('description.backup_alert', ['%date%' => $latest->getCheckedAt()->format('d/m/Y H:i'), '%summary%' => $latest->getSummary()], 'config'),
            'severity' => $severity,
            'url' => $this->urlGenerator->generate('management_health_check_index'),
            // Every config this alert is about is itself "restricted" (see configs.json's backup group), so an admin below ROLE_SUPER_ADMIN can neither read the settings behind it nor change what it reports: showing them a backup they have no way to act on is noise, not information
            'role' => 'ROLE_SUPER_ADMIN',
        ];
    }

    private function maxAgeHours(): int
    {
        $configured = (int) $this->configService->get('site-backup-max-age-hours');

        return $configured > 0 ? $configured : self::DEFAULT_MAX_AGE_HOURS;
    }

    private function hoursSince(\DateTimeInterface $checkedAt): int
    {
        return intdiv(max(0, time() - $checkedAt->getTimestamp()), 3600);
    }
}
