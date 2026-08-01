<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

// Follows the run queued by the Health check page's "Run now" button (see HealthCheckController::run()), which hands its jobs to a Messenger worker and returns immediately: without this the page states the results are coming and then never moves, leaving no way of telling a finished run from a worker that was never started. Progress is read off the results themselves rather than from the queue - the rows are the only thing web request and worker share - and the queued run is kept in the session, so it's followed by the admin who started it and by no one else
class HealthCheckRunProgress
{
    // Session key holding the run being followed, dropped as soon as it's over
    private const SESSION_KEY = 'c975l_health_check_run';

    // A run is given up on after this many seconds, however many kinds are still missing: a provider returning no rows at all (a gallery with no photo yet) records nothing to be counted, and would otherwise leave the page polling forever
    public const TIMEOUT = 900;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly HealthCheckResultRepository $healthCheckResultRepository,
    ) {
    }

    // Starts following a run of the given kinds, from now on - anything they recorded before this moment belongs to a previous run and must not count towards this one
    public function start(array $kinds): void
    {
        $this->session()?->set(self::SESSION_KEY, ['startedAt' => time(), 'kinds' => array_values($kinds)]);
    }

    // Where the followed run stands, or null when there's none - clearing it on the way out once it's over, so the reload that follows the last job comes back to a page with no progress banner on it. @return array{done: int, total: int, finished: bool, timedOut: bool}|null
    public function poll(): ?array
    {
        $run = $this->session()?->get(self::SESSION_KEY);
        if (!\is_array($run)) {
            return null;
        }

        $done = array_intersect(
            $this->healthCheckResultRepository->findKindsCheckedSince((new \DateTimeImmutable())->setTimestamp($run['startedAt'])),
            $run['kinds'],
        );

        $timedOut = time() - $run['startedAt'] >= self::TIMEOUT && \count($done) < \count($run['kinds']);
        $finished = \count($done) >= \count($run['kinds']) || $timedOut;

        if ($finished) {
            $this->clear();
        }

        return ['done' => \count($done), 'total' => \count($run['kinds']), 'finished' => $finished, 'timedOut' => $timedOut];
    }

    public function clear(): void
    {
        $this->session()?->remove(self::SESSION_KEY);
    }

    // Null rather than RequestStack::getSession()'s exception: a console command asking a provider for its rows has no session, and a run nobody is watching is not an error
    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->hasSession() ? $request->getSession() : null;
    }
}
