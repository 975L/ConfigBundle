<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Repository;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HealthCheckResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HealthCheckResult::class);
    }

    // One row per (url, kind), keeping only the most recent checkedAt - backs the "Health check" dashboard table, which shows the current state, not the full history. Deduped in PHP (dataset stays small, see HealthCheckResult's class comment) then re-sorted by url/kind for a stable display order
    public function findLatestPerUrlAndKind(): array
    {
        $rows = $this->createQueryBuilder('h')
            ->orderBy('h.checkedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($rows as $row) {
            $key = $row->getUrl() . '|' . $row->getKind();
            $latest[$key] ??= $row;
        }
        $latest = array_values($latest);

        usort($latest, static fn (HealthCheckResult $a, HealthCheckResult $b) => [$a->getUrl(), $a->getKind()] <=> [$b->getUrl(), $b->getKind()]);

        return $latest;
    }

    // One row per kind for a single url, most recent checkedAt only - backs a per-page health check panel (e.g. c975l/site-bundle's Page edit screen), the same dedup rule as findLatestPerUrlAndKind() but scoped to one page instead of every page
    public function findLatestByUrl(string $url): array
    {
        $rows = $this->createQueryBuilder('h')
            ->andWhere('h.url = :url')
            ->setParameter('url', $url)
            ->orderBy('h.checkedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($rows as $row) {
            $latest[$row->getKind()] ??= $row;
        }
        $latest = array_values($latest);

        usort($latest, static fn (HealthCheckResult $a, HealthCheckResult $b) => $a->getKind() <=> $b->getKind());

        return $latest;
    }

    // Status counts (ok/warning/error) grouped by calendar day, across every kind/url - the "is our site's health improving or degrading" trend chart on the Health check page (see HealthCheckController), not a per-page breakdown. Capped to the last $maxDates distinct days so the chart stays readable as history accumulates (see HealthCheckResult's own class comment on why history isn't pruned)
    public function findStatusCountsByDate(int $maxDates = 12): array
    {
        $rows = $this->createQueryBuilder('h')
            ->select('h.url', 'h.kind', 'h.status', 'h.checkedAt')
            ->orderBy('h.checkedAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Dedupe to the latest run per (day, url, kind) before counting - a check re-run several times the same day (manual click + cron, or repeated testing) must not inflate that day's counts, same rule as findLatestPerUrlAndKind() applied per day instead of overall
        $latestPerDayAndCheck = [];
        foreach ($rows as $row) {
            $key = $row['checkedAt']->format('Y-m-d') . '|' . $row['url'] . '|' . $row['kind'];
            $latestPerDayAndCheck[$key] = $row;
        }

        $byDate = [];
        foreach ($latestPerDayAndCheck as $row) {
            $day = $row['checkedAt']->format('Y-m-d');
            $byDate[$day][$row['status']] = ($byDate[$day][$row['status']] ?? 0) + 1;
        }

        $dates = \array_slice(array_keys($byDate), -$maxDates);

        $series = [HealthCheckResult::STATUS_OK => [], HealthCheckResult::STATUS_WARNING => [], HealthCheckResult::STATUS_ERROR => []];
        foreach ($dates as $date) {
            foreach ($series as $status => &$values) {
                $values[] = $byDate[$date][$status] ?? 0;
            }
        }

        return ['dates' => $dates, 'series' => $series];
    }
}
