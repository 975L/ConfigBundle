<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Schedule;

// Adds to the app's schedule what every installed bundle declared through MaintenanceTaskProviderInterface, so the scaffolded MaintenanceSchedule lists no command of its own and stays the same file on every site
class MaintenanceScheduleBuilder
{
    public function __construct(
        /** @var iterable<MaintenanceTaskProviderInterface> */
        private readonly iterable $maintenanceTaskProviders,
        private readonly ScheduleSpreader $scheduleSpreader,
    ) {
    }

    // Schedules every declared task, spread over this install's own minutes. $except drops a command line the site doesn't want run at all, and the app remains free to ->add() entries of its own afterwards
    public function addTasks(Schedule $schedule, array $except = []): Schedule
    {
        $scheduled = [];

        foreach ($this->maintenanceTaskProviders as $provider) {
            foreach ($provider->getMaintenanceTasks() as $task) {
                // A command declared twice (two bundles shipping the same task, or one the site also lists by hand) would make Schedule::add() throw at worker start-up, killing every other task with it
                if (in_array($task->command, $scheduled, true) || in_array($task->command, $except, true)) {
                    continue;
                }

                $schedule->add($this->scheduleSpreader->spread($task->expression, new RunCommandMessage($task->command)));
                $scheduled[] = $task->command;
            }
        }

        return $schedule;
    }
}
