<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\DatabaseLoadHealthCheckProvider;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

class DatabaseLoadHealthCheckProviderTest extends TestCase
{
    private const SAMPLE_SECONDS = 5;

    // A quiet server: whatever the two readings hold is what the sample measures
    private const BASE_COUNTERS = [
        'Com_begin' => 1000, 'Com_commit' => 1000, 'Com_rollback' => 0, 'Com_select' => 5000,
        'Questions' => 10000, 'Com_insert' => 100, 'Com_insert_select' => 0, 'Com_update' => 100,
        'Com_update_multi' => 0, 'Com_delete' => 0, 'Com_delete_multi' => 0, 'Com_replace' => 0,
        'Com_replace_select' => 0, 'Slow_queries' => 0, 'Innodb_row_lock_waits' => 0,
        'Created_tmp_disk_tables' => 0, 'Aborted_connects' => 0, 'Threads_connected' => 3,
        'Max_used_connections' => 12, 'Uptime' => 2592000,
    ];

    private function counters(array $overrides = []): array
    {
        return array_map('strval', $overrides + self::BASE_COUNTERS);
    }

    // The counters as the server names them, one reading per call - the provider takes two, sampleSeconds apart
    private function createConnection(array $readings, ?AbstractPlatform $platform = null): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform ?? new MariaDBPlatform());
        $connection->method('fetchAllKeyValue')->willReturnOnConsecutiveCalls(...$readings);

        return $connection;
    }

    private function createPreviousResult(array $counters, \DateTimeInterface $checkedAt): HealthCheckResult
    {
        return (new HealthCheckResult())
            ->setKind(DatabaseLoadHealthCheckProvider::KIND)
            ->setUrl('https://example.com')
            ->setStatus(HealthCheckResult::STATUS_OK)
            ->setSummary('summary')
            ->setDetails(['counters' => array_map('intval', $counters)])
            ->setCheckedAt($checkedAt);
    }

    private function createProvider(Connection $connection, array $previousResults = [], string $siteUrl = 'https://example.com/', ?MockClock $clock = null): DatabaseLoadHealthCheckProvider
    {
        $repository = $this->createStub(HealthCheckResultRepository::class);
        $repository->method('findLatestByKind')->willReturn($previousResults);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($siteUrl);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return new DatabaseLoadHealthCheckProvider(
            $connection,
            $repository,
            $configService,
            $translator,
            $clock ?? new MockClock(),
            self::SAMPLE_SECONDS
        );
    }

    // Nothing else in the health check knows where the site is until site-url is set, and this provider is no exception
    public function testNoRowIsWrittenWithoutASiteUrl(): void
    {
        $rows = $this->createProvider($this->createConnection([$this->counters()]), [], '')->runChecks();

        $this->assertSame([], $rows);
    }

    // SHOW GLOBAL STATUS is MySQL/MariaDB's own, and a site on anything else must read as "not measured" rather than as measured and clean
    public function testAnotherDatabasePlatformIsSkipped(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $rows = $this->createProvider($connection)->runChecks();

        $this->assertCount(1, $rows);
        $this->assertSame(HealthCheckResult::STATUS_SKIPPED, $rows[0]['status']);
        $this->assertSame('label.health_check_database_load_unsupported', $rows[0]['summary']);
    }

    // A managed host can refuse the statement to an application user, which says nothing about the site's own health
    public function testAServerRefusingTheStatementIsSkipped(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $connection->method('fetchAllKeyValue')->willThrowException(new \RuntimeException('Access denied'));

        $rows = $this->createProvider($connection)->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_SKIPPED, $rows[0]['status']);
        $this->assertSame('label.health_check_database_load_unavailable', $rows[0]['summary']);
    }

    // The very first run has no previous reading to subtract, so it only has its own few seconds to report - and says so
    public function testTheFirstRunReportsTheInstantSampleAsABaseline(): void
    {
        $rows = $this->createProvider($this->createConnection([
            $this->counters(),
            $this->counters(['Com_begin' => 1050, 'Com_commit' => 1050, 'Com_update' => 105]),
        ]))->runChecks();

        $this->assertSame('label.health_check_database_load_summary_baseline', $rows[0]['summary']);
        $this->assertSame(10.0, $rows[0]['details']['instant']['transactions']);
        $this->assertNull($rows[0]['details']['window']);
    }

    // The raw counters are the whole point of the row: they are what the next run subtracts to get an average over days
    public function testTheRawCountersAreKeptForTheNextRun(): void
    {
        $rows = $this->createProvider($this->createConnection([$this->counters(), $this->counters()]))->runChecks();

        $this->assertSame(1000, $rows[0]['details']['counters']['Com_begin']);
        $this->assertSame(2592000, $rows[0]['details']['counters']['Uptime']);
    }

    // 50 transactions in 5 seconds, 5 of which wrote something: what a site opening transactions around reads looks like
    public function testTransactionsWithoutAnyWriteAreAWarning(): void
    {
        $rows = $this->createProvider($this->createConnection([
            $this->counters(),
            $this->counters(['Com_begin' => 1050, 'Com_commit' => 1050, 'Com_update' => 105]),
        ]))->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $rows[0]['status']);
        $this->assertSame(0.9, $rows[0]['details']['instant']['emptyShare']);
    }

    // Every transaction wrote, which is exactly what transactions are for, however many of them there are
    public function testTransactionsThatAllWriteAreNotAWarning(): void
    {
        $rows = $this->createProvider($this->createConnection([
            $this->counters(),
            $this->counters(['Com_begin' => 1050, 'Com_commit' => 1050, 'Com_update' => 150]),
        ]))->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_OK, $rows[0]['status']);
        $this->assertSame(0.0, $rows[0]['details']['instant']['emptyShare']);
    }

    // An idle site opens a transaction now and then and there is nothing to optimise about it, whatever the ratio says
    public function testARateBelowOneTransactionPerSecondIsNeverAWarning(): void
    {
        $rows = $this->createProvider($this->createConnection([
            $this->counters(),
            $this->counters(['Com_begin' => 1002, 'Com_commit' => 1002]),
        ]))->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_OK, $rows[0]['status']);
        $this->assertSame(0.4, $rows[0]['details']['instant']['transactions']);
    }

    // The number a five-second sample cannot give: what the server averaged over the days separating two runs
    public function testThePreviousRunGivesTheAverageOverTheDaysSeparatingThem(): void
    {
        $clock = new MockClock('2026-08-01 04:00:00');
        $previous = $this->createPreviousResult(self::BASE_COUNTERS, new \DateTime('2026-07-25 04:00:00'));

        // 604800 seconds apart, 604800 more transactions: one per second on average, none of which wrote
        $rows = $this->createProvider($this->createConnection([
            $this->counters(['Com_begin' => 605800, 'Com_commit' => 605800]),
            $this->counters(['Com_begin' => 605800, 'Com_commit' => 605800]),
        ]), [$previous], 'https://example.com/', $clock)->runChecks();

        $this->assertSame('label.health_check_database_load_summary', $rows[0]['summary']);
        $this->assertSame(7.0, $rows[0]['details']['window']['days']);
        $this->assertSame(1.0, $rows[0]['details']['window']['transactions']);
        $this->assertSame(1.0, $rows[0]['details']['window']['emptyShare']);
        $this->assertSame(HealthCheckResult::STATUS_WARNING, $rows[0]['status']);
    }

    // Every counter resets when the server restarts, and the difference with the previous run would read as a suspiciously quiet week
    public function testAServerRestartedSinceThePreviousRunHasNoAverage(): void
    {
        $clock = new MockClock('2026-08-01 04:00:00');
        $previous = $this->createPreviousResult(self::BASE_COUNTERS, new \DateTime('2026-07-25 04:00:00'));

        // Up for an hour, where the two runs are a week apart
        $rows = $this->createProvider($this->createConnection([
            $this->counters(['Uptime' => 3600, 'Com_begin' => 10, 'Com_commit' => 10]),
            $this->counters(['Uptime' => 3605, 'Com_begin' => 15, 'Com_commit' => 15]),
        ]), [$previous], 'https://example.com/', $clock)->runChecks();

        $this->assertNull($rows[0]['details']['window']);
        $this->assertSame('label.health_check_database_load_summary_baseline', $rows[0]['summary']);
    }

    // Counters going backwards between the two readings is the server having restarted mid-sample - there is nothing to divide
    public function testCountersGoingBackwardsWithinTheSampleAreSkipped(): void
    {
        $rows = $this->createProvider($this->createConnection([
            $this->counters(['Com_begin' => 1000]),
            $this->counters(['Com_begin' => 10]),
        ]))->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_SKIPPED, $rows[0]['status']);
        $this->assertSame('label.health_check_database_load_no_sample', $rows[0]['summary']);
        $this->assertArrayHasKey('counters', $rows[0]['details']);
    }

    // The sample is a sleep, and the health check's own runner is the only caller allowed to pay for it (see HealthCheckProviderInterface)
    public function testTheSampleLastsTheDeclaredNumberOfSeconds(): void
    {
        $clock = new MockClock('2026-08-01 04:00:00');

        $rows = $this->createProvider($this->createConnection([$this->counters(), $this->counters()]), [], 'https://example.com/', $clock)->runChecks();

        $this->assertSame(self::SAMPLE_SECONDS, $rows[0]['details']['instant']['seconds']);
        $this->assertSame('2026-08-01 04:00:05', $clock->now()->format('Y-m-d H:i:s'));
    }

    // Site-wide row, and the url is what the dashboard links to
    public function testTheRowCarriesTheSiteUrlWithoutItsTrailingSlash(): void
    {
        $rows = $this->createProvider($this->createConnection([$this->counters(), $this->counters()]))->runChecks();

        $this->assertSame('https://example.com', $rows[0]['url']);
        $this->assertSame('label.health_check_database_load', $rows[0]['label']);
    }
}
