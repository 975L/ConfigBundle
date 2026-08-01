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
use c975L\ConfigBundle\Management\DatabaseLoadHealthCheckAdviceProvider;
use c975L\ConfigBundle\Management\DatabaseLoadHealthCheckProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class DatabaseLoadHealthCheckAdviceProviderTest extends TestCase
{
    private function createResult(?array $details, string $status = HealthCheckResult::STATUS_WARNING, string $kind = DatabaseLoadHealthCheckProvider::KIND): HealthCheckResult
    {
        return (new HealthCheckResult())
            ->setKind($kind)
            ->setUrl('https://example.com')
            ->setStatus($status)
            ->setSummary('summary')
            ->setDetails($details)
            ->setCheckedAt(new \DateTime());
    }

    private function createProvider(): DatabaseLoadHealthCheckAdviceProvider
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return new DatabaseLoadHealthCheckAdviceProvider($translator);
    }

    private function rates(array $overrides = []): array
    {
        return $overrides + [
            'seconds' => 5,
            'transactions' => 10.0,
            'commits' => 10.0,
            'rollbacks' => 0.0,
            'selects' => 20.0,
            'writes' => 0.5,
            'emptyShare' => 0.95,
            'slowQueries' => 0,
            'lockWaits' => 0,
            'diskTmpTables' => 0,
            'abortedConnects' => 0,
        ];
    }

    private function texts(array $advice): array
    {
        return array_map(static fn (array $line): string => $line['text'], reset($advice) ?: []);
    }

    public function testAnotherKindIsIgnored(): void
    {
        $advice = $this->createProvider()->buildAdvice([
            $this->createResult(['instant' => $this->rates(), 'window' => null], HealthCheckResult::STATUS_WARNING, 'pagespeed'),
        ]);

        $this->assertSame([], $advice);
    }

    public function testEmptyTransactionsAreExplainedOnAWarningRow(): void
    {
        $advice = $this->createProvider()->buildAdvice([
            $this->createResult(['instant' => $this->rates(), 'window' => null]),
        ]);

        $this->assertContains('label.health_check_advice_database_load_empty', $this->texts($advice));
    }

    // The provider's own thresholds decide what an acceptable share is, and an ok row has nothing to say about it
    public function testEmptyTransactionsAreNotExplainedOnAnOkRow(): void
    {
        $advice = $this->createProvider()->buildAdvice([
            $this->createResult(['instant' => $this->rates(), 'window' => null], HealthCheckResult::STATUS_OK),
        ]);

        $this->assertSame([], $advice);
    }

    // The whole point of sampling during the run: a rate that holds while nobody is on the site belongs to a background process, not to the traffic
    public function testARateHoldingDuringTheRunIsReportedAsBackgroundLoad(): void
    {
        $advice = $this->createProvider()->buildAdvice([
            $this->createResult([
                'instant' => $this->rates(['transactions' => 12.0]),
                'window' => $this->rates(['transactions' => 13.9, 'days' => 7.0]),
            ]),
        ]);

        $this->assertContains('label.health_check_advice_database_load_background', $this->texts($advice));
    }

    // A rate that collapses when the traffic does is exactly what a busy site looks like, and there is nothing to say about it
    public function testARateCollapsingOutsideTrafficIsNotReportedAsBackgroundLoad(): void
    {
        $advice = $this->createProvider()->buildAdvice([
            $this->createResult([
                'instant' => $this->rates(['transactions' => 1.0]),
                'window' => $this->rates(['transactions' => 13.9, 'days' => 7.0]),
            ]),
        ]);

        $this->assertNotContains('label.health_check_advice_database_load_background', $this->texts($advice));
    }

    public function testTheServerCountersOfTheWindowAreReportedOneLineEach(): void
    {
        $advice = $this->createProvider()->buildAdvice([
            $this->createResult([
                'instant' => $this->rates(),
                'window' => $this->rates(['slowQueries' => 12, 'lockWaits' => 3, 'abortedConnects' => 5, 'days' => 7.0]),
            ]),
        ]);

        $texts = $this->texts($advice);
        $this->assertContains('label.health_check_advice_database_load_slow', $texts);
        $this->assertContains('label.health_check_advice_database_load_locks', $texts);
        $this->assertContains('label.health_check_advice_database_load_aborted', $texts);
    }

    // Counters at zero are the normal case, and a line saying "0 slow queries" on every healthy site is noise
    public function testTheServerCountersAtZeroSayNothing(): void
    {
        $advice = $this->createProvider()->buildAdvice([
            $this->createResult([
                'instant' => $this->rates(['transactions' => 1.0]),
                'window' => $this->rates(['transactions' => 13.9, 'days' => 7.0]),
            ], HealthCheckResult::STATUS_OK),
        ]);

        $this->assertSame([], $advice);
    }

    // A row skipped before anything could be measured (see DatabaseLoadHealthCheckProvider) carries no rates at all
    public function testARowWithoutAnyRateIsIgnored(): void
    {
        $advice = $this->createProvider()->buildAdvice([$this->createResult(['counters' => ['Com_begin' => 10]])]);

        $this->assertSame([], $advice);
    }

    public function testARowWithoutAnyDetailsIsIgnored(): void
    {
        $advice = $this->createProvider()->buildAdvice([$this->createResult(null)]);

        $this->assertSame([], $advice);
    }
}
