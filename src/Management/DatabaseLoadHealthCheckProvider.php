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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// What the database server itself did between two runs, read from its own counters (SHOW GLOBAL STATUS) - the one thing every other health check misses, all of them looking at the site from the outside. The number it watches is transactions, not queries: a page firing an n+1 is already caught by c975l:dev-profile:run, while transactions opened around nothing at all are invisible everywhere (they cost no page a millisecond a developer would notice) and are what saturates a database server first, long before its cpu
class DatabaseLoadHealthCheckProvider implements HealthCheckProviderInterface
{
    public const KIND = 'database-load';

    // Read as-is from SHOW GLOBAL STATUS and kept in the row's details, so the next run can subtract them: every one of them counts up since the server started, an absolute value saying nothing on its own
    private const COUNTERS = [
        'Com_begin', 'Com_commit', 'Com_rollback', 'Com_select', 'Questions',
        'Com_insert', 'Com_insert_select', 'Com_update', 'Com_update_multi',
        'Com_delete', 'Com_delete_multi', 'Com_replace', 'Com_replace_select',
        'Slow_queries', 'Innodb_row_lock_waits', 'Created_tmp_disk_tables',
        'Aborted_connects', 'Threads_connected', 'Max_used_connections', 'Uptime',
    ];

    // Everything that writes a row, whatever the statement - a transaction holding none of them wrote nothing
    private const WRITE_COUNTERS = [
        'Com_insert', 'Com_insert_select', 'Com_update', 'Com_update_multi',
        'Com_delete', 'Com_delete_multi', 'Com_replace', 'Com_replace_select',
    ];

    // Two readings this many seconds apart, giving the rate at the very moment of the run - the whole point being that the weekly run happens at night (see the scaffold's MaintenanceSchedule): whatever the server still does with no visitor on the site is background noise, not traffic. It sleeps, which is why nothing calls this provider from a controller (see HealthCheckProviderInterface)
    private const SAMPLE_SECONDS = 5;

    // Below this, the two readings are too close together for their difference to mean anything
    private const MIN_SAMPLE_SECONDS = 0.1;

    // Under a transaction per second there is nothing to optimise, whatever the ratio - a site can be perfectly idle
    private const MIN_RATE = 1.0;

    // Past this share of transactions holding no write at all, they're being opened around reads, or around nothing
    private const MAX_EMPTY_SHARE = 0.5;

    public function __construct(
        private readonly Connection $connection,
        private readonly HealthCheckResultRepository $healthCheckResultRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
        // Not sleep()/time(): the sample below is the one thing here that cannot be tested without waiting for it, and a MockClock's sleep() advances its own time instead of the process
        private readonly ClockInterface $clock,
        private readonly int $sampleSeconds = self::SAMPLE_SECONDS,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    // Deliberately a single row: the previous run is found by kind alone (see HealthCheckResultRepository::findLatestByKind()), which only holds while one run writes one row
    public function runChecks(): array
    {
        $url = rtrim((string) $this->configService->get('site-url'), '/');
        if ('' === $url) {
            return [];
        }

        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            return [$this->row($url, HealthCheckResult::STATUS_SKIPPED, 'label.health_check_database_load_unsupported', ['%platform%' => (new \ReflectionClass($platform))->getShortName()])];
        }

        try {
            $sampledAt = $this->clock->now();
            $before = $this->readCounters();
            $this->clock->sleep($this->sampleSeconds);
            $counters = $this->readCounters();
            // Measured rather than assumed to be sampleSeconds: the two readings are two round trips to the database, and the rate is divided by this
            $elapsed = (float) $this->clock->now()->format('U.u') - (float) $sampledAt->format('U.u');
        } catch (\Throwable $e) {
            // A managed host can refuse SHOW GLOBAL STATUS to an application user - reported as skipped, never as an error: nothing is wrong with the site
            return [$this->row($url, HealthCheckResult::STATUS_SKIPPED, 'label.health_check_database_load_unavailable', ['%message%' => $e->getMessage()])];
        }

        // Nothing measurable: the two readings landed too close together, or the counters went backwards between them (the server restarted mid-sample). The raw counters are still worth keeping - they're what the next run subtracts
        $instant = $this->rates($before, $counters, $elapsed);
        if (null === $instant) {
            return [$this->row($url, HealthCheckResult::STATUS_SKIPPED, 'label.health_check_database_load_no_sample', [], ['counters' => $counters])];
        }

        // The window covers days and the instant sample a few seconds, so the window is the one to judge on whenever there is one - the instant sample is what the very first run has, and what the advice compares the window against afterwards
        $window = $this->windowRates($counters);
        $judged = $window ?? $instant;

        return [$this->row($url, $this->statusOf($judged), $this->summaryId($window), [
            '%rate%' => $instant['transactions'],
            '%windowRate%' => $window['transactions'] ?? 0,
            '%days%' => $window['days'] ?? 0,
            '%empty%' => (int) round(100 * $judged['emptyShare']),
        ], ['counters' => $counters, 'instant' => $instant, 'window' => $window])];
    }

    // Only transactions opened around no write at all are an offence, and only once there are enough of them to be worth an hour of anybody's time
    private function statusOf(array $rates): string
    {
        return $rates['transactions'] >= self::MIN_RATE && $rates['emptyShare'] > self::MAX_EMPTY_SHARE
            ? HealthCheckResult::STATUS_WARNING
            : HealthCheckResult::STATUS_OK;
    }

    // The first run has no previous one to subtract, and says so rather than showing the instant sample as if it were an average
    private function summaryId(?array $window): string
    {
        return null === $window ? 'label.health_check_database_load_summary_baseline' : 'label.health_check_database_load_summary';
    }

    private function readCounters(): array
    {
        // Names come back capitalised on every server this runs on, but the lookup is folded anyway: SHOW GLOBAL STATUS is one of the statements whose case has moved between MySQL and MariaDB releases
        $status = array_change_key_case($this->connection->fetchAllKeyValue('SHOW GLOBAL STATUS'));

        $counters = [];
        foreach (self::COUNTERS as $name) {
            $counters[$name] = (int) ($status[strtolower($name)] ?? 0);
        }

        return $counters;
    }

    // Rates over the period separating two readings, null when the period is too short to divide by, or when the counters went backwards - they all reset when the server restarts, and a negative delta would otherwise be reported as a suspiciously quiet week
    private function rates(array $before, array $after, float $seconds): ?array
    {
        if ($seconds < self::MIN_SAMPLE_SECONDS) {
            return null;
        }

        foreach (self::COUNTERS as $name) {
            if ('Threads_connected' !== $name && 'Max_used_connections' !== $name && $after[$name] < $before[$name]) {
                return null;
            }
        }

        $commits = $after['Com_commit'] - $before['Com_commit'];
        $writes = $this->writes($after) - $this->writes($before);

        return [
            'seconds' => (int) round($seconds),
            'transactions' => round(($after['Com_begin'] - $before['Com_begin']) / $seconds, 1),
            'commits' => round($commits / $seconds, 1),
            'rollbacks' => round(($after['Com_rollback'] - $before['Com_rollback']) / $seconds, 1),
            'selects' => round(($after['Com_select'] - $before['Com_select']) / $seconds, 1),
            'writes' => round($writes / $seconds, 1),
            // Conservative on purpose: writes made outside any transaction (autocommit) count here too, so they hide empty transactions rather than invent them - what this share reports is a floor
            'emptyShare' => $commits > 0 ? round(max(0, $commits - $writes) / $commits, 2) : 0.0,
            'slowQueries' => $after['Slow_queries'] - $before['Slow_queries'],
            'lockWaits' => $after['Innodb_row_lock_waits'] - $before['Innodb_row_lock_waits'],
            'diskTmpTables' => $after['Created_tmp_disk_tables'] - $before['Created_tmp_disk_tables'],
            'abortedConnects' => $after['Aborted_connects'] - $before['Aborted_connects'],
        ];
    }

    // The same rates against the previous run's own reading, ie. what the server averaged over the days separating them - the number a five-second sample cannot give, and the one a five-second sample is compared against
    private function windowRates(array $counters): ?array
    {
        $previous = $this->healthCheckResultRepository->findLatestByKind(self::KIND, 1)[0] ?? null;
        $before = ($previous?->getDetails() ?? [])['counters'] ?? null;
        if (null === $previous || !\is_array($before)) {
            return null;
        }

        $seconds = $this->clock->now()->getTimestamp() - $previous->getCheckedAt()->getTimestamp();

        // The counters have been reset since, whatever they now read: the server has not been up for as long as the two runs are apart
        if ($seconds <= 0 || $counters['Uptime'] < $seconds) {
            return null;
        }

        // The union fills whatever the previous run didn't store with the current reading, ie. a zero delta: a counter added to COUNTERS after that run was written has no past value, and must not be read as having jumped from nothing
        $rates = $this->rates($before + $counters, $counters, (float) $seconds);

        return null === $rates ? null : $rates + ['days' => round($seconds / 86400, 1)];
    }

    private function writes(array $counters): int
    {
        $writes = 0;
        foreach (self::WRITE_COUNTERS as $name) {
            $writes += $counters[$name];
        }

        return $writes;
    }

    private function row(string $url, string $status, string $translationId, array $parameters = [], ?array $details = null): array
    {
        return [
            'url' => $url,
            'label' => $this->translator->trans('label.health_check_database_load', [], 'config'),
            'status' => $status,
            'summary' => $this->translator->trans($translationId, $parameters, 'config'),
            'details' => $details,
        ];
    }
}
