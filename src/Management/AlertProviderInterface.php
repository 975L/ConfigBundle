<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Entity\Config;

interface AlertProviderInterface
{
    /**
     * 'role' is optional - omit it for an alert every admin should act on, set it (e.g. 'ROLE_SUPER_ADMIN', see BackupAlertProvider) to hide the alert from users lacking it, same key as ShortcutProviderInterface's. An alert nobody below that role can do anything about - because the configs behind it are themselves restricted - is noise on their dashboard, not information.
     *
     * @return list<array{label: string, description: ?string, severity: Config::SEVERITY_*, url: string, role?: string}>
     */
    public function getAlerts(): array;
}
