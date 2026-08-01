<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\HealthCheckRunProgress;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class HealthCheckRunProgressTest extends TestCase
{
    // Returns [RequestStack, Session] so a test can assert on what the followed run left behind
    private function createRequestStackWithSession(): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return [$requestStack, $session];
    }

    private function createRepository(array $kindsCheckedSince = []): HealthCheckResultRepository
    {
        $repository = $this->createStub(HealthCheckResultRepository::class);
        $repository->method('findKindsCheckedSince')->willReturn($kindsCheckedSince);

        return $repository;
    }

    public function testPollReturnsNullWhenNoRunIsBeingFollowed(): void
    {
        [$requestStack] = $this->createRequestStackWithSession();

        $this->assertNull((new HealthCheckRunProgress($requestStack, $this->createRepository()))->poll());
    }

    public function testPollCountsTheKindsHavingRecordedSomethingSinceTheRunStarted(): void
    {
        [$requestStack] = $this->createRequestStackWithSession();
        $runProgress = new HealthCheckRunProgress($requestStack, $this->createRepository(['pagespeed', 'w3c']));
        $runProgress->start(['pagespeed', 'w3c', 'ssl-certificate']);

        $this->assertSame(
            ['done' => 2, 'total' => 3, 'finished' => false, 'timedOut' => false],
            $runProgress->poll(),
        );
    }

    // A kind recorded by a scheduled run of its own (the backup rows land every few hours) must not count towards a run that never queued it
    public function testPollIgnoresKindsThatWereNotQueued(): void
    {
        [$requestStack] = $this->createRequestStackWithSession();
        $runProgress = new HealthCheckRunProgress($requestStack, $this->createRepository(['pagespeed', 'backup']));
        $runProgress->start(['pagespeed', 'w3c']);

        $this->assertSame(1, $runProgress->poll()['done']);
    }

    public function testPollReportsTheRunAsFinishedOnceEveryQueuedKindHasLanded(): void
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $runProgress = new HealthCheckRunProgress($requestStack, $this->createRepository(['pagespeed', 'w3c']));
        $runProgress->start(['pagespeed', 'w3c']);

        $this->assertSame(
            ['done' => 2, 'total' => 2, 'finished' => true, 'timedOut' => false],
            $runProgress->poll(),
        );

        // Dropped on the way out, so the reload that follows the last job comes back to a page with no banner left to poll
        $this->assertSame([], $session->all());
        $this->assertNull($runProgress->poll());
    }

    // A provider returning no rows at all (a gallery with no photo yet) records nothing to be counted, and would otherwise leave the page polling forever
    public function testPollGivesUpOnARunThatHasBeenGoingForTooLong(): void
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $runProgress = new HealthCheckRunProgress($requestStack, $this->createRepository(['pagespeed']));
        $runProgress->start(['pagespeed', 'w3c']);

        // Backdated rather than waited out, the timeout being counted in quarter-hours
        $run = $session->get('c975l_health_check_run');
        $run['startedAt'] -= HealthCheckRunProgress::TIMEOUT;
        $session->set('c975l_health_check_run', $run);

        $this->assertSame(
            ['done' => 1, 'total' => 2, 'finished' => true, 'timedOut' => true],
            $runProgress->poll(),
        );
        $this->assertSame([], $session->all());
    }

    // The queued run is followed by the admin who started it and by no one else: a console command asking a provider for its rows has no session at all
    public function testStartAndPollAreNoOpsWithoutASession(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $runProgress = new HealthCheckRunProgress($requestStack, $this->createRepository(['pagespeed']));

        $runProgress->start(['pagespeed']);

        $this->assertNull($runProgress->poll());
    }

    public function testClearStopsFollowingTheRun(): void
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $runProgress = new HealthCheckRunProgress($requestStack, $this->createRepository());
        $runProgress->start(['pagespeed']);

        $runProgress->clear();

        $this->assertSame([], $session->all());
        $this->assertNull($runProgress->poll());
    }
}
