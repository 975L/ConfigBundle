<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// To add a HealthCheckProvider, you need to: add the Management Folder in the src/ folder of your bundle; create a class implementing HealthCheckProviderInterface; ConfigBundle will automatically detect it and run it from the c975l:health-check:run command (see HealthCheckRunner), persisting its rows for the "Health check" dashboard page. A provider that calls a remote API (PageSpeed Insights, W3C validator...) should never be invoked from a controller - only from the command, so a dashboard page load never blocks on a slow third-party call
interface HealthCheckProviderInterface
{
    // Stable identifier for this provider's rows (eg. "pagespeed", "security-headers", "w3c-html"), stored as HealthCheckResult::kind
    public function getKind(): string;

    // One entry per checked url: ['url' => string, 'label' => ?string, 'status' => HealthCheckResult::STATUS_*, 'summary' => string, 'details' => array, 'editUrl' => ?string]
    // editUrl is optional (omit or null when the row has no admin CRUD counterpart, eg. a site-wide check) - the admin edit screen for the entity behind this row (eg. SiteBundle's Page edit screen), shown in the dashboard table alongside the tested url
    public function runChecks(): array;
}
