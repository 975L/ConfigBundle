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
use c975L\ConfigBundle\Management\StatusProviderInterface;
use c975L\ConfigBundle\Management\StatusReportBuilder;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;

class StatusReportBuilderTest extends TestCase
{
    // One health check row, only the fields the report reads being set
    private function row(string $status, string $kind = 'ssl', string $url = 'https://papa-calin.com', string $checkedAt = '2026-08-01 03:00:00'): HealthCheckResult
    {
        return (new HealthCheckResult())
            ->setKind($kind)
            ->setUrl($url)
            ->setStatus($status)
            ->setSummary('summary')
            ->setDetails(['raw' => 'payload that must not travel'])
            ->setCheckedAt(new \DateTimeImmutable($checkedAt));
    }

    // The repository is a Doctrine ServiceEntityRepository: stubbed rather than built, no kernel and no database here
    private function createBuilder(array | \Throwable $rows = [], array $statusProviders = []): StatusReportBuilder
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('https://papa-calin.com');

        $repository = $this->createStub(HealthCheckResultRepository::class);

        if ($rows instanceof \Throwable) {
            $repository->method('findLatestPerUrlAndKind')->willThrowException($rows);
        } else {
            $repository->method('findLatestPerUrlAndKind')->willReturn($rows);
        }

        return new StatusReportBuilder($statusProviders, $configService, $repository, 'prod');
    }

    public function testBuildCarriesTheSiteIdentityAndItsVersions(): void
    {
        $report = $this->createBuilder()->build();

        $this->assertSame(StatusReportBuilder::VERSION, $report['version']);
        $this->assertSame('https://papa-calin.com', $report['site']);
        $this->assertSame('prod', $report['environment']);
        $this->assertSame(\PHP_VERSION, $report['php']);
        $this->assertNotSame('', $report['generatedAt']);
    }

    // Bundles, not the whole dependency tree - and never Symfony's own, whose version the report already carries as its own field
    public function testPackagesListsBundlesWithoutTheSymfonyOnes(): void
    {
        $packages = $this->createBuilder()->build()['packages'];

        $this->assertArrayHasKey('c975l/config-bundle', $packages);

        foreach (array_keys($packages) as $name) {
            $this->assertStringStartsNotWith('symfony/', $name);
        }
    }

    public function testChecksCountEveryStatusAndKeepTheLatestRunDate(): void
    {
        $checks = $this->createBuilder([
            $this->row(HealthCheckResult::STATUS_OK, checkedAt: '2026-08-01 03:00:00'),
            $this->row(HealthCheckResult::STATUS_OK, checkedAt: '2026-08-01 04:00:00'),
            $this->row(HealthCheckResult::STATUS_WARNING),
            $this->row(HealthCheckResult::STATUS_SKIPPED),
        ])->build()['checks'];

        $this->assertSame(['ok' => 2, 'warning' => 1, 'error' => 0, 'skipped' => 1], $checks['counts']);
        $this->assertStringStartsWith('2026-08-01T04:00:00', $checks['lastRunAt']);
    }

    // A site in warning is a site to improve, and its own dashboard already lists where: only what needs acting on today travels
    public function testIssuesCarryTheErrorRowsOnly(): void
    {
        $checks = $this->createBuilder([
            $this->row(HealthCheckResult::STATUS_OK),
            $this->row(HealthCheckResult::STATUS_WARNING, 'headers'),
            $this->row(HealthCheckResult::STATUS_ERROR, 'ssl'),
        ])->build()['checks'];

        $this->assertCount(1, $checks['issues']);
        $this->assertSame('ssl', $checks['issues'][0]['kind']);
        $this->assertFalse($checks['issuesTruncated']);
    }

    // The checkers' raw payloads are what makes a row big and occasionally revealing: the receiver learns where it hurts, the site keeps why
    public function testIssuesLeaveTheDetailsBehind(): void
    {
        $checks = $this->createBuilder([$this->row(HealthCheckResult::STATUS_ERROR)])->build()['checks'];

        $this->assertSame(['kind', 'url', 'summary'], array_keys($checks['issues'][0]));
    }

    // A site with hundreds of broken pages must send a payload sized by its state, not by its content - and say that the list was cut
    public function testIssuesAreCappedAndSayThatTheyAre(): void
    {
        $rows = [];

        for ($i = 0; $i < 25; ++$i) {
            $rows[] = $this->row(HealthCheckResult::STATUS_ERROR, 'ssl', 'https://papa-calin.com/' . $i);
        }

        $checks = $this->createBuilder($rows)->build()['checks'];

        $this->assertCount(20, $checks['issues']);
        $this->assertTrue($checks['issuesTruncated']);
        $this->assertSame(25, $checks['counts'][HealthCheckResult::STATUS_ERROR]);
    }

    // A site whose migrations haven't run yet still has a version and a package list worth reporting, and "unavailable" is not "no issue found"
    public function testChecksIsNullWhenTheRepositoryThrows(): void
    {
        $this->assertNull($this->createBuilder(new \RuntimeException('Table not found'))->build()['checks']);
    }

    // A site with no provider at all - the common case - must not send "extra": [], which breaks a receiver reading it as a keyed structure
    public function testExtraIsAnObjectWhenNoProviderIsInstalled(): void
    {
        $this->assertEquals(new \stdClass(), $this->createBuilder()->build()['extra']);
    }

    public function testExtraCarriesOneSectionPerProvider(): void
    {
        $extra = $this->createBuilder([], [$this->createProvider('shop', ['pendingOrders' => 3])])->build()['extra'];

        $this->assertSame(['pendingOrders' => 3], $extra->shop);
    }

    // A provider that throws must not cost the whole report: a site that goes silent reads as a much worse problem than one section that failed
    public function testProviderThrowingOnDataReportsUnderItsOwnKey(): void
    {
        $extra = $this->createBuilder([], [$this->createProvider('shop', new \RuntimeException('Database gone'))])->build()['extra'];

        $this->assertSame(['error' => 'Database gone'], $extra->shop);
    }

    // The key being what throws is the case that used to escape the catch, the report dying with it
    public function testProviderThrowingOnItsKeyFallsBackOnItsClass(): void
    {
        $provider = new class implements StatusProviderInterface {
            public function getStatusKey(): string
            {
                throw new \RuntimeException('No key');
            }

            public function getStatusData(): array
            {
                return [];
            }
        };

        $extra = $this->createBuilder([], [$provider])->build()['extra'];

        $this->assertSame(['error' => 'No key'], ((array) $extra)[$provider::class]);
    }

    // One provider failing leaves the others' sections in place
    public function testAFailingProviderDoesNotCostTheOthers(): void
    {
        $extra = $this->createBuilder([], [
            $this->createProvider('shop', new \RuntimeException('Database gone')),
            $this->createProvider('book', ['titles' => 12]),
        ])->build()['extra'];

        $this->assertSame(['error' => 'Database gone'], $extra->shop);
        $this->assertSame(['titles' => 12], $extra->book);
    }

    private function createProvider(string $key, array | \Throwable $data): StatusProviderInterface
    {
        $provider = $this->createStub(StatusProviderInterface::class);
        $provider->method('getStatusKey')->willReturn($key);

        if ($data instanceof \Throwable) {
            $provider->method('getStatusData')->willThrowException($data);
        } else {
            $provider->method('getStatusData')->willReturn($data);
        }

        return $provider;
    }
}
