<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

interface DashboardWidgetProviderInterface
{
    // Each entry: ['template' => string, 'context' => array]. The dashboard template only loops and includes - it never contains business logic about what a widget is (e.g. UiBundle's DonovanWidgetProvider only returns an entry when its own isEnabled()/role check passes, so an unconfigured feature stays entirely absent rather than showing a disabled placeholder); return [] when nothing to show.
    public function getDashboardWidgets(): array;
}
