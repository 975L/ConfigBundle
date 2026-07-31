<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Attribute\AsHealthCheck;
use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckFrequencyAwareInterface;
use c975L\ConfigBundle\Management\HealthCheckProviderInterface;
use c975L\ConfigBundle\Management\HealthCheckRunner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class HealthCheckRunnerTest extends TestCase
{
    private function createProvider(string $kind, array $rows): HealthCheckProviderInterface
    {
        $provider = $this->createStub(HealthCheckProviderInterface::class);
        $provider->method('getKind')->willReturn($kind);
        $provider->method('runChecks')->willReturn($rows);

        return $provider;
    }

    public function testRunPersistsOneHealthCheckResultPerRowAndFlushesOnce(): void
    {
        $rows = [
            ['url' => 'https://example.com/pages/home/', 'label' => 'Home', 'status' => HealthCheckResult::STATUS_OK, 'summary' => 'Perf 95', 'details' => ['performance' => 95], 'editUrl' => '/admin/page/1/edit'],
            ['url' => 'https://example.com/pages/contact/', 'label' => null, 'status' => HealthCheckResult::STATUS_WARNING, 'summary' => 'Perf 60', 'details' => null],
        ];
        $provider = $this->createProvider('pagespeed', $rows);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (HealthCheckResult $result) use (&$persisted): void {
            $persisted[] = $result;
        });
        $entityManager->expects($this->once())->method('flush');

        $runner = new HealthCheckRunner([$provider], $entityManager);
        $counts = $runner->run();

        $this->assertSame(['pagespeed' => 2], $counts);
        $this->assertCount(2, $persisted);

        $this->assertSame('pagespeed', $persisted[0]->getKind());
        $this->assertSame('https://example.com/pages/home/', $persisted[0]->getUrl());
        $this->assertSame('Home', $persisted[0]->getLabel());
        $this->assertSame(HealthCheckResult::STATUS_OK, $persisted[0]->getStatus());
        $this->assertSame('Perf 95', $persisted[0]->getSummary());
        $this->assertSame(['performance' => 95], $persisted[0]->getDetails());
        $this->assertSame('/admin/page/1/edit', $persisted[0]->getEditUrl());

        $this->assertNull($persisted[1]->getLabel());
        $this->assertNull($persisted[1]->getDetails());
        $this->assertNull($persisted[1]->getEditUrl());

        // Every row from the same provider run shares the same checkedAt, so they can be grouped as one run
        $this->assertEquals($persisted[0]->getCheckedAt(), $persisted[1]->getCheckedAt());
    }

    public function testRunReturnsZeroCountForAProviderWithNoRows(): void
    {
        $provider = $this->createProvider('w3c-html', []);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        // A run persisting nothing is a true no-op - flush() would only pay for computing a changeset with nothing in it
        $entityManager->expects($this->never())->method('flush');

        $runner = new HealthCheckRunner([$provider], $entityManager);
        $counts = $runner->run();

        $this->assertSame(['w3c-html' => 0], $counts);
    }

    public function testRunWithNoProvidersReturnsEmptyCounts(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $runner = new HealthCheckRunner([], $entityManager);

        $this->assertSame([], $runner->run());
    }

    // Lets the scheduler run a costly/paid provider (eg. "wave") on its own cron entry, separate from the free ones
    public function testRunWithOnlyKindsSkipsProvidersNotInTheList(): void
    {
        $pagespeed = $this->createProvider('pagespeed', [['url' => 'https://example.com/', 'label' => null, 'status' => HealthCheckResult::STATUS_OK, 'summary' => 'ok', 'details' => null]]);
        $wave = $this->createProvider('wave', [['url' => 'https://example.com/', 'label' => null, 'status' => HealthCheckResult::STATUS_OK, 'summary' => 'ok', 'details' => null]]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $runner = new HealthCheckRunner([$pagespeed, $wave], $entityManager);
        $counts = $runner->run(['wave']);

        $this->assertSame(['wave' => 1], $counts);
    }

    public function testRunWithEmptyOnlyKindsRunsEveryProvider(): void
    {
        $pagespeed = $this->createProvider('pagespeed', []);
        $wave = $this->createProvider('wave', []);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $runner = new HealthCheckRunner([$pagespeed, $wave], $entityManager);
        $counts = $runner->run();

        $this->assertSame(['pagespeed' => 0, 'wave' => 0], $counts);
    }

    // What the dashboard's "Run health check now" button queues one job from (see HealthCheckController::run())
    public function testGetKindsListsEveryRegisteredKind(): void
    {
        $runner = new HealthCheckRunner(
            [$this->createProvider('pagespeed', []), $this->createProvider('wave', [])],
            $this->createStub(EntityManagerInterface::class),
        );

        $this->assertSame(['pagespeed', 'wave'], $runner->getKinds());
    }

    // Two providers of the same kind is one job to queue, not two identical ones
    public function testGetKindsDeduplicates(): void
    {
        $runner = new HealthCheckRunner(
            [$this->createProvider('urls-book', []), $this->createProvider('urls-book', [])],
            $this->createStub(EntityManagerInterface::class),
        );

        $this->assertSame(['urls-book'], $runner->getKinds());
    }

    public function testGetKindsIsEmptyWithoutAnyProvider(): void
    {
        $runner = new HealthCheckRunner([], $this->createStub(EntityManagerInterface::class));

        $this->assertSame([], $runner->getKinds());
    }

    // A provider saying nothing is weekly, which is what keeps AsHealthCheck optional
    public function testAProviderWithoutTheAttributeIsWeekly(): void
    {
        $runner = $this->createFrequencyRunner();

        $this->assertSame(['pages' => 0], $runner->run([], AsHealthCheck::FREQUENCY_WEEKLY));
    }

    public function testOnlyTheProvidersDeclaringTheAskedCadenceRun(): void
    {
        $runner = $this->createFrequencyRunner();

        $this->assertSame(['photos' => 0], $runner->run([], AsHealthCheck::FREQUENCY_MONTHLY));
    }

    // What the dashboard's "Run health check now" button still does, and what a cron entry naming no cadence would
    public function testNoFrequencyRunsEveryProviderWhateverItDeclares(): void
    {
        $runner = $this->createFrequencyRunner();

        $this->assertSame(['pages' => 0, 'photos' => 0], $runner->run());
    }

    // Both filters narrow the same run rather than one winning over the other
    public function testKindAndFrequencyCombine(): void
    {
        $runner = $this->createFrequencyRunner();

        $this->assertSame([], $runner->run(['pages'], AsHealthCheck::FREQUENCY_MONTHLY));
        $this->assertSame(['pages' => 0], $runner->run(['pages'], AsHealthCheck::FREQUENCY_WEEKLY));
    }

    // One class registered once per source cannot state its cadence on itself, so the instance answers for it (see SiteBundle's DeclaredUrlsHealthCheckProvider)
    public function testAFrequencyAwareProviderDecidesPerInstance(): void
    {
        $runner = new HealthCheckRunner($this->createInstanceAwareProviders(), $this->createStub(EntityManagerInterface::class));

        $this->assertSame(['urls-book' => 0], $runner->run([], AsHealthCheck::FREQUENCY_WEEKLY));
        $this->assertSame(['urls-gallery' => 0], $runner->run([], AsHealthCheck::FREQUENCY_MONTHLY));
    }

    // Two instances of the very same class, each with its own cadence - what the attribute alone cannot express
    private function createInstanceAwareProviders(): array
    {
        $provider = new class ('urls-book', AsHealthCheck::FREQUENCY_WEEKLY) implements HealthCheckProviderInterface, HealthCheckFrequencyAwareInterface {
            public function __construct(private readonly string $kind, private readonly string $frequency)
            {
            }

            public function getKind(): string
            {
                return $this->kind;
            }

            public function getFrequency(): string
            {
                return $this->frequency;
            }

            public function runChecks(): array
            {
                return [];
            }
        };

        return [$provider, new ($provider::class)('urls-gallery', AsHealthCheck::FREQUENCY_MONTHLY)];
    }

    // One provider of each cadence: the weekly one says nothing, the monthly one carries the attribute
    private function createFrequencyRunner(): HealthCheckRunner
    {
        $weekly = new class implements HealthCheckProviderInterface {
            public function getKind(): string
            {
                return 'pages';
            }

            public function runChecks(): array
            {
                return [];
            }
        };

        $monthly = new #[AsHealthCheck(AsHealthCheck::FREQUENCY_MONTHLY)] class implements HealthCheckProviderInterface {
            public function getKind(): string
            {
                return 'photos';
            }

            public function runChecks(): array
            {
                return [];
            }
        };

        return new HealthCheckRunner([$weekly, $monthly], $this->createStub(EntityManagerInterface::class));
    }
}
