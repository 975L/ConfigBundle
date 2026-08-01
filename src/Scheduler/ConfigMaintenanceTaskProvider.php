<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Scheduler;

// The commands this bundle needs run on a cadence, declared here rather than listed by every site in its own MaintenanceSchedule
class ConfigMaintenanceTaskProvider implements MaintenanceTaskProviderInterface
{
    public function getMaintenanceTasks(): array
    {
        return [
            // Sitemaps: nightly, once the day's content is in
            new MaintenanceTask('# #(0-2) * * *', 'c975l:sitemaps:create'),
            // Backup: every 6 hours (DB dumped table by table; files complete on the first run and every site-backup-full-interval-months after that, modified-since-last-run in between)
            new MaintenanceTask('# */6 * * *', 'c975l:config:backup'),
            // Digest of the week's backups, on its own entry rather than as --report on a backup run: a summary riding on a backup only exists if that run reaches its last line, and no mail at all is what nobody notices
            new MaintenanceTask('# #(2-5) * * 1', 'c975l:config:backup:digest'),
            // Health check: a cadence, never a list of kinds - every provider declares its own with AsHealthCheck (weekly unless it says otherwise), so these two already account for whatever bundles the site installs later
            new MaintenanceTask('# #(3-6) * * 0', 'c975l:health-check:run --frequency=weekly'),
            // The heavy ones apart: a gallery declares one url per photo, by far the longest run. Their day of the month is drawn too, rather than the 1st for every site
            new MaintenanceTask('# #(4-7) # * *', 'c975l:health-check:run --frequency=monthly'),
        ];
    }
}
