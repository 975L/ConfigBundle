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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Turns what c975l:config:backup just did into a HealthCheckResult row, so every run leaves a trace instead of only the one that happens to carry --report. Deliberately not a HealthCheckProviderInterface: a provider is re-run by c975l:health-check:run, and re-running a backup to find out how the last one went is not a check, it's another backup
class BackupResultRecorder
{
    // Reported as its own kind rather than folded into an existing one, so it gets its own row in the Health check page's site-wide section (see HealthCheckController::SITE_WIDE_KINDS)
    public const KIND = 'backup';

    // Below this share of the previous run's SQL archive, the run is downgraded to a warning: a dump that suddenly holds half of what it held last week is the failure mode no per-table error ever reports, the tables all having dumped "successfully" into a truncated result
    private const SHRINK_RATIO = 0.5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HealthCheckResultRepository $healthCheckResultRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // @param array $outcome see BackupCommand::outcome() for its shape
    public function record(array $outcome): void
    {
        $warnings = array_merge($outcome['warnings'], $this->shrinkWarnings($outcome));

        $result = (new HealthCheckResult())
            ->setKind(self::KIND)
            ->setUrl($outcome['url'])
            ->setLabel(null)
            ->setStatus($this->status($outcome['errors'], $warnings))
            ->setSummary($this->summary($outcome, $warnings))
            ->setDetails(array_merge($outcome, ['warnings' => $warnings]))
            ->setCheckedAt(new \DateTime());

        $this->entityManager->persist($result);
        $this->entityManager->flush();
    }

    private function status(array $errors, array $warnings): string
    {
        return match (true) {
            [] !== $errors => HealthCheckResult::STATUS_ERROR,
            [] !== $warnings => HealthCheckResult::STATUS_WARNING,
            default => HealthCheckResult::STATUS_OK,
        };
    }

    // Compared against the previous run of this kind, never against a fixed size: what a healthy archive weighs is entirely site-specific, and the only meaningful reference is what the same site produced last time
    private function shrinkWarnings(array $outcome): array
    {
        $previous = $this->previousSqlBytes();
        if (null === $previous || $previous <= 0 || $outcome['sqlBytes'] <= 0) {
            return [];
        }

        $ratio = $outcome['sqlBytes'] / $previous;
        if ($ratio >= self::SHRINK_RATIO) {
            return [];
        }

        return [$this->translator->trans(
            'label.health_check_backup_sql_shrank',
            ['%percent%' => (int) round((1 - $ratio) * 100), '%previous%' => $this->formatBytes($previous)],
            'config',
        )];
    }

    // The run before this one - record() hasn't persisted the current row yet, so the newest row in database is still the previous run
    private function previousSqlBytes(): ?int
    {
        $rows = $this->healthCheckResultRepository->findLatestByKind(self::KIND, 1);

        return $rows ? ($rows[0]->getDetails()['sqlBytes'] ?? null) : null;
    }

    // The one line the dashboard table shows: what was dumped, what it weighs and how long it took - the numbers a "backup ok" message never gives, and the only ones that tell an empty dump from a real one
    private function summary(array $outcome, array $warnings): string
    {
        $summary = $this->translator->trans('label.health_check_summary_backup', [
            '%tables%' => $outcome['tables']['dumped'],
            '%sql%' => $this->formatBytes($outcome['sqlBytes']),
            '%files%' => $this->filesSummary($outcome),
            '%duration%' => $this->formatDuration($outcome['durationSeconds']),
        ], 'config');

        $counts = [
            'label.health_check_backup_errors' => \count($outcome['errors']),
            'label.health_check_backup_warnings' => \count($warnings),
        ];
        foreach ($counts as $translationId => $count) {
            if ($count > 0) {
                $summary .= ' · ' . $this->translator->trans($translationId, ['%count%' => $count], 'config');
            }
        }

        return $summary;
    }

    private function filesSummary(array $outcome): string
    {
        return match ($outcome['foldersMode']) {
            'complete' => $this->translator->trans('label.health_check_backup_files_complete', ['%size%' => $this->formatBytes($outcome['foldersBytes'])], 'config'),
            'partial' => $this->translator->trans('label.health_check_backup_files_partial', ['%count%' => $outcome['foldersFiles'], '%size%' => $this->formatBytes($outcome['foldersBytes'])], 'config'),
            default => $this->translator->trans('label.health_check_backup_files_none', [], 'config'),
        };
    }

    private function formatBytes(int $bytes): string
    {
        return ByteFormatter::format($bytes);
    }

    private function formatDuration(int $seconds): string
    {
        return $seconds < 60
            ? sprintf('%d s', $seconds)
            : sprintf('%d min %d s', intdiv($seconds, 60), $seconds % 60);
    }
}
