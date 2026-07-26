<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;

// To add advice for one or more HealthCheckProviderInterface kinds, implement this interface (eg. SiteBundle's PageHealthCheckAdviceBuilder) - ConfigBundle merges every registered provider's advice (see HealthCheckAdviceBuilder) so the dashboard "Health check" page and any CRUD's own "Health check" tab (eg. SiteBundle's Page edit screen) render advice through the exact same shared table (health_check/_table.html.twig)
interface HealthCheckAdviceProviderInterface
{
    // Keyed by HealthCheckAdviceBuilder::key() (only the results this provider actually has something to say about) - a same-kind result exists per checked url, so keying by kind would have every url's row show the same advice
    // Optional "items" on a line: the individual offenders behind a "%count% of them" line (eg. each image without alt text), rendered as a collapsed list under the line, each with its own optional link and that link's label
    // @param HealthCheckResult[] $results
    // @return array<string, array{text: string, url: ?string, items?: array{text: string, url: ?string, label: ?string}[]}[]>
    public function buildAdvice(array $results): array;
}
