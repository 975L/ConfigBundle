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
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Composes the weekly "everything went fine" email out of the HealthCheckResult rows every backup run leaves behind. Deliberately reads the window rather than one run: `c975l:config:backup --report` only ever tells what the run carrying it just did, so a Wednesday failure between two healthy Mondays is invisible in it, and a Monday that dies before sending leaves no mail at all - which is the one signal nobody notices. Built here rather than in the command, so what the digest says can be tested without a mailer
class BackupDigestBuilder
{
    public const DEFAULT_DAYS = 7;

    // Not a HealthCheckResult status: no run was recorded at all over the window, which is neither an ok nor a failed backup but the absence of any
    public const STATUS_NONE = 'none';

    public function __construct(
        private readonly HealthCheckResultRepository $healthCheckResultRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // @return array{status: string, subject: string, body: string, runs: int}
    public function build(int $days = self::DEFAULT_DAYS): array
    {
        $now = new \DateTimeImmutable();
        $since = $now->modify(sprintf('-%d days', $days));
        $runs = $this->healthCheckResultRepository->findByKindSince(BackupResultRecorder::KIND, $since);

        $counts = $this->countByStatus($runs);
        $gapHours = $this->longestGapHours($runs, $now);
        $status = $this->status($runs, $counts, $gapHours);

        return [
            'status' => $status,
            'subject' => $this->subject($status, $days),
            'body' => $this->body($runs, $counts, $gapHours, $days, $since),
            'runs' => \count($runs),
        ];
    }

    private function countByStatus(array $runs): array
    {
        $counts = [
            HealthCheckResult::STATUS_OK => 0,
            HealthCheckResult::STATUS_WARNING => 0,
            HealthCheckResult::STATUS_ERROR => 0,
        ];

        foreach ($runs as $run) {
            if (isset($counts[$run->getStatus()])) {
                ++$counts[$run->getStatus()];
            }
        }

        return $counts;
    }

    // The longest silence in the window, the stretch between the last run and now included: a scheduler that stopped on Wednesday and a run that failed both need saying, and only the first one leaves nothing at all behind to say it. The stretch before the first run is left out on purpose - a site whose backups started three days ago hasn't skipped the four days before that
    private function longestGapHours(array $runs, \DateTimeImmutable $now): ?int
    {
        if (empty($runs)) {
            return null;
        }

        $timestamps = array_map(fn (HealthCheckResult $run) => $run->getCheckedAt()->getTimestamp(), $runs);
        sort($timestamps);
        $timestamps[] = $now->getTimestamp();

        $gap = 0;
        for ($i = 1, $total = \count($timestamps); $i < $total; ++$i) {
            $gap = max($gap, $timestamps[$i] - $timestamps[$i - 1]);
        }

        return intdiv($gap, 3600);
    }

    // A gap weighs as much as a failure: a week of runs that all succeeded but stopped on Wednesday is not an "ok" week
    private function status(array $runs, array $counts, ?int $gapHours): string
    {
        return match (true) {
            empty($runs) => self::STATUS_NONE,
            $counts[HealthCheckResult::STATUS_ERROR] > 0 => HealthCheckResult::STATUS_ERROR,
            $gapHours > $this->maxAgeHours() => HealthCheckResult::STATUS_ERROR,
            $counts[HealthCheckResult::STATUS_WARNING] > 0 => HealthCheckResult::STATUS_WARNING,
            default => HealthCheckResult::STATUS_OK,
        };
    }

    // The verdict goes in the subject line, so a week of digests across every site is read from the mailbox list without opening one
    private function subject(string $status, int $days): string
    {
        $labelId = match ($status) {
            self::STATUS_NONE => 'label.backup_digest_subject_none',
            HealthCheckResult::STATUS_ERROR => 'label.backup_digest_subject_error',
            HealthCheckResult::STATUS_WARNING => 'label.backup_digest_subject_warning',
            default => 'label.backup_digest_subject_ok',
        };

        return $this->trans($labelId, ['%days%' => $days, '%site%' => $this->siteDomain()]);
    }

    private function body(array $runs, array $counts, ?int $gapHours, int $days, \DateTimeImmutable $since): string
    {
        $lines = [
            $this->trans('label.backup_digest_intro', [
                '%site%' => $this->siteDomain(),
                '%days%' => $days,
                '%since%' => $since->format('d/m/Y H:i'),
            ]),
            '',
        ];

        if (empty($runs)) {
            $lines[] = $this->trans('label.backup_digest_none', ['%days%' => $days]);

            return implode("\n", $lines);
        }

        $lines[] = $this->trans('label.backup_digest_runs', [
            '%count%' => \count($runs),
            '%ok%' => $counts[HealthCheckResult::STATUS_OK],
            '%warning%' => $counts[HealthCheckResult::STATUS_WARNING],
            '%error%' => $counts[HealthCheckResult::STATUS_ERROR],
        ]);
        $lines[] = $this->trans('label.backup_digest_last_run', [
            '%date%' => $runs[0]->getCheckedAt()->format('d/m/Y H:i'),
            '%summary%' => $runs[0]->getSummary(),
        ]);

        return implode("\n", array_merge(
            $lines,
            $this->sqlTrendLines($runs),
            $this->gapLines($gapHours),
            $this->retentionLines($runs[0]),
            $this->issueLines($runs, 'errors', 'label.backup_digest_errors'),
            $this->issueLines($runs, 'warnings', 'label.backup_digest_warnings'),
        ));
    }

    // Where the archive started the week and where it ended it: the shrink warning only ever compares a run against the one before it, so a slow drift over 28 runs never trips it and is only visible from the two ends
    private function sqlTrendLines(array $runs): array
    {
        $last = $this->detail($runs[0], 'sqlBytes', 0);
        $first = $this->detail($runs[\count($runs) - 1], 'sqlBytes', 0);

        if (\count($runs) < 2 || $first <= 0 || $last <= 0) {
            return [];
        }

        return [$this->trans('label.backup_digest_sql_trend', [
            '%first%' => ByteFormatter::format($first),
            '%last%' => ByteFormatter::format($last),
        ])];
    }

    // Only worth a line once it exceeds what the schedule allows: six hours between two six-hourly runs is the normal state of things, not news
    private function gapLines(?int $gapHours): array
    {
        if (null === $gapHours || $gapHours <= $this->maxAgeHours()) {
            return [];
        }

        return [$this->trans('label.backup_digest_gap', ['%hours%' => $gapHours, '%max%' => $this->maxAgeHours()])];
    }

    // What the server still holds, straight from the last run's own purge: the digest says a backup happened, this says it's still there to restore from
    private function retentionLines(HealthCheckResult $latest): array
    {
        $retention = $this->detail($latest, 'retention', []);
        if (empty($retention)) {
            return [];
        }

        return [$this->trans('label.backup_digest_retention', [
            '%days%' => $retention['days'] ?? 0,
            '%runs%' => $retention['runs'] ?? 0,
            '%oldest%' => $retention['oldest'] ?? '-',
        ])];
    }

    // Deduplicated across the window and counted: a dump failing on the same table every six hours is one problem repeated 28 times, and listing it 28 times is how a report stops being read
    private function issueLines(array $runs, string $key, string $headingId): array
    {
        $occurrences = [];
        foreach ($runs as $run) {
            foreach ($this->detail($run, $key, []) as $message) {
                $occurrences[$message] = ($occurrences[$message] ?? 0) + 1;
            }
        }

        if (empty($occurrences)) {
            return [];
        }

        $lines = ['', $this->trans($headingId)];
        foreach ($occurrences as $message => $count) {
            $lines[] = $this->trans('label.backup_digest_issue', ['%message%' => $message, '%count%' => $count]);
        }

        return $lines;
    }

    // Rows recorded by an earlier version of the bundle have no details at all, and must not turn the digest into a crash
    private function detail(HealthCheckResult $run, string $key, mixed $default): mixed
    {
        return ($run->getDetails() ?? [])[$key] ?? $default;
    }

    // The same threshold BackupAlertProvider raises its staleness alert on, so the dashboard and the email never disagree on what "late" means
    private function maxAgeHours(): int
    {
        $configured = (int) $this->configService->get('site-backup-max-age-hours');

        return $configured > 0 ? $configured : BackupAlertProvider::DEFAULT_MAX_AGE_HOURS;
    }

    private function siteDomain(): string
    {
        $url = (string) $this->configService->get('site-url');

        return parse_url($url, PHP_URL_HOST) ?: $url;
    }

    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, 'config');
    }
}
