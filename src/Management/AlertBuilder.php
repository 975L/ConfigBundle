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

// Merges the dashboard alerts contributed by every AlertProvider (bundles depending on ConfigBundle), grouped by severity (danger first)
class AlertBuilder
{
    public function __construct(
        private readonly iterable $alertProviders,
    ) {
    }

    // Every alert, across every provider, grouped by severity - for the main dashboard
    public function getAlerts(): array
    {
        $alerts = ProviderMerger::merge($this->alertProviders, fn (AlertProviderInterface $provider) => $provider->getAlerts());

        return self::groupBySeverity($alerts);
    }

    // Groups a single provider's flat alert list by severity - for a CRUD's own index page, which only wants its own alerts (see ConfigCrudController/SiteGraphicCrudController)
    public static function groupBySeverity(array $alerts): array
    {
        $grouped = [
            Config::SEVERITY_DANGER => [],
            Config::SEVERITY_WARNING => [],
            Config::SEVERITY_INFO => [],
        ];

        foreach ($alerts as $alert) {
            $grouped[$alert['severity']][] = $alert;
        }

        return $grouped;
    }
}
