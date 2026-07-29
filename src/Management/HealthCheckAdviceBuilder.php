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

// Merges the health check advice contributed by every HealthCheckAdviceProvider (eg. SiteBundle's PageHealthCheckAdviceBuilder) - shared by the dashboard "Health check" page and any CRUD's own "Health check" tab, so both render advice through the exact same table (see health_check/_table.html.twig)
class HealthCheckAdviceBuilder
{
    public function __construct(
        private readonly iterable $healthCheckAdviceProviders,
    ) {
    }

    // The key advice is grouped under, one per checked row - kind alone isn't enough, the dashboard's Health check page lists one row per url *and* per kind, so keying by kind had every url's row showing the last checked url's advice. Not the result's id: a result that hasn't been persisted yet has none. The same format is rebuilt inline by health_check/_table.html.twig to look each row's own advice up
    public static function key(HealthCheckResult $result): string
    {
        return $result->getKind() . '|' . $result->getUrl();
    }

    // Not ProviderMerger::merge(): two providers' lines are appended, not one overwriting the other
    // @param HealthCheckResult[] $results
    // @return array<string, array{text: string, url: ?string, items?: array{text: string, url: ?string, label: ?string}[]}[]>
    public function build(array $results): array
    {
        $advice = [];
        foreach ($this->healthCheckAdviceProviders as $provider) {
            foreach ($provider->buildAdvice($results) as $key => $lines) {
                $advice[$key] = array_merge($advice[$key] ?? [], $lines);
            }
        }

        return $advice;
    }
}
