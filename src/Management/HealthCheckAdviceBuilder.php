<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Merges the health check advice contributed by every HealthCheckAdviceProvider (eg. SiteBundle's PageHealthCheckAdviceBuilder) - shared by the dashboard "Health check" page and any CRUD's own "Health check" tab, so both render advice through the exact same table (see health_check/_table.html.twig)
class HealthCheckAdviceBuilder
{
    public function __construct(
        private readonly iterable $healthCheckAdviceProviders,
    ) {
    }

    // @param \c975L\ConfigBundle\Entity\HealthCheckResult[] $results
    // @return array<string, array{text: string, url: ?string}[]>
    public function build(array $results): array
    {
        return ProviderMerger::merge($this->healthCheckAdviceProviders, fn (HealthCheckAdviceProviderInterface $provider) => $provider->buildAdvice($results));
    }
}
