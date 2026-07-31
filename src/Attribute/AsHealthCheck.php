<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Attribute;

// Declares how often a HealthCheckProvider wants to run. Purely optional: a provider is registered by its interface alone (see TaggedInterfacePass), and every provider without this attribute is weekly. Only a provider that is long or costly needs to say so, and it says it itself - so a cron entry asks for a cadence ("--frequency=monthly") instead of naming kinds, and installing a bundle never means editing a site's MaintenanceSchedule again
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsHealthCheck
{
    // The cadence the scaffold's cron entries ask for, one entry per value
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCIES = [self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY];

    public function __construct(
        public readonly string $frequency = self::FREQUENCY_WEEKLY,
    ) {
        if (!\in_array($frequency, self::FREQUENCIES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid health check frequency "%s", expected one of "%s".', $frequency, implode('", "', self::FREQUENCIES)));
        }
    }
}
