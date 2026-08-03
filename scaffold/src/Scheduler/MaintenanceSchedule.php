<?php

namespace App\Scheduler;

use c975L\ConfigBundle\Scheduler\MaintenanceScheduleBuilder;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('site')]
class MaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly MaintenanceScheduleBuilder $builder,
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        // Every installed c975L bundle declares its own commands (ConfigBundle's MaintenanceTaskProviderInterface) and this file lists none of them, so installing or removing a bundle needs no edit here and an upgraded scaffold can be propagated rather than merged. To drop one you don't want run at all, pass its command line: addTasks($schedule, ['c975l:health-check:run --frequency=monthly'])
        $schedule = $this->builder->addTasks(
            (new Schedule())->stateful($this->cache)
        );

        // This site's own commands go here, spread like the others with ConfigBundle's ScheduleSpreader, or on a fixed time with a plain RecurringMessage::cron()
        return $schedule;
    }
}
