<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Scheduler;

/**
 * Implement this to have your bundle's own commands scheduled by the site, without the app having to list them by hand in its MaintenanceSchedule - collected by MaintenanceScheduleBuilder, see readme. It's what keeps the scaffolded schedule identical from one site to the next, so a bundle upgrade can be propagated to them all rather than merged into each: a site that installs your bundle gets your tasks, one that removes it stops running them, and neither has anything to edit.
 */
interface MaintenanceTaskProviderInterface
{
    /**
     * The commands to run and how often, [] when there's nothing to schedule.
     *
     * @return list<MaintenanceTask>
     */
    public function getMaintenanceTasks(): array;
}
