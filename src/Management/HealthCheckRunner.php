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
use Doctrine\ORM\EntityManagerInterface;

// Runs every registered HealthCheckProvider and persists their rows - called from c975l:health-check:run only (see HealthCheckProviderInterface), never from a controller, so a slow third-party API call never blocks a dashboard request
class HealthCheckRunner
{
    public function __construct(
        private readonly iterable $healthCheckProviders,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // $onlyKinds restricts the run to the given provider kinds (see HealthCheckProviderInterface::getKind()) - lets the scheduler run a costly/paid provider (e.g. "wave") on its own, less frequent cron entry, separate from the free ones, without needing one command per provider. Empty (the default, and what the dashboard's "Run health check now" button always uses) runs every registered provider. Returns the number of rows persisted, per provider kind
    public function run(array $onlyKinds = []): array
    {
        $counts = [];

        foreach ($this->healthCheckProviders as $provider) {
            $kind = $provider->getKind();
            if ($onlyKinds && !\in_array($kind, $onlyKinds, true)) {
                continue;
            }

            $checkedAt = new \DateTime();
            $rows = $provider->runChecks();

            foreach ($rows as $row) {
                $result = (new HealthCheckResult())
                    ->setKind($kind)
                    ->setUrl($row['url'])
                    ->setLabel($row['label'] ?? null)
                    ->setStatus($row['status'])
                    ->setSummary($row['summary'])
                    ->setDetails($row['details'] ?? null)
                    ->setEditUrl($row['editUrl'] ?? null)
                    ->setCheckedAt($checkedAt);
                $this->entityManager->persist($result);
            }

            $counts[$kind] = \count($rows);
        }

        // Skips flush() entirely on a true no-op (no provider matched $onlyKinds, or every matched provider returned zero rows) - flush() walks the whole UnitOfWork's changeset, not just what this method touched, so there's a real cost to paying for it when nothing was persisted
        if (array_sum($counts) > 0) {
            $this->entityManager->flush();
        }

        return $counts;
    }
}
