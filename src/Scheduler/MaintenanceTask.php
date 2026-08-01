<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One command a bundle wants run on a cadence, as declared by a MaintenanceTaskProviderInterface
// #[Exclude] because services.yaml registers all of src/ as public autowired services, and a value object with scalar arguments can't be autowired: without it, every app installing the bundle fails to compile its container
#[Exclude]
class MaintenanceTask
{
    public function __construct(
        // The cadence, as a cron expression whose "#" are drawn per install by ScheduleSpreader (see readme): give a heavy command a window wide enough to spread into
        public readonly string $expression,
        // The command line, options included ('c975l:health-check:run --frequency=weekly'), as it would be typed after bin/console
        public readonly string $command,
    ) {
    }
}
