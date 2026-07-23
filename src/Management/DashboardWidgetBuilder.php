<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Merges the dashboard widgets contributed by every DashboardWidgetProvider (e.g. UiBundle's Donovan card) - order of appearance across providers doesn't matter enough to sort, unlike EssentialActionBuilder's actions
class DashboardWidgetBuilder
{
    public function __construct(
        private readonly iterable $dashboardWidgetProviders,
    ) {
    }

    public function getWidgets(): array
    {
        return ProviderMerger::merge($this->dashboardWidgetProviders, fn (DashboardWidgetProviderInterface $provider) => $provider->getDashboardWidgets());
    }
}
