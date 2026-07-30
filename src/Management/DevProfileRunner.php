<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use Symfony\Component\DependencyInjection\Attribute\When;

// Profiles every path the registered DevProfilePathProviderInterface implementations declare, and judges each one - called from c975l:dev-profile:run only. Dev-only counterpart of HealthCheckRunner, and deliberately persists nothing: the health check watches a live site over time, this one hands the developer the list of what to fix in the code sitting on their machine, right now
#[When('dev')]
class DevProfileRunner
{
    public function __construct(
        private readonly iterable $devProfilePathProviders,
        private readonly DevProfileCollector $devProfileCollector,
        private readonly DevProfileAnalyzer $devProfileAnalyzer,
    ) {
    }

    // $onlyPaths restricts the run to the given paths, and accepts a path no provider declares (profiling one page while working on it). Empty runs every declared path. Returns one entry per path: ['path' => string, 'label' => ?string, 'metrics' => array, 'issues' => array]
    public function run(array $onlyPaths = []): array
    {
        $paths = $this->collectPaths($onlyPaths);
        if (!$paths) {
            return [];
        }

        // The kernel stays booted from one path to the next, so the very first one pays for everything the others will find warm (config read from the database, twig templates compiled, cache pools filled) and would be reported carrying numbers none of the following ones show. Running it once and dropping the result puts every profiled path on the same footing
        $this->devProfileCollector->collect(reset($paths)['path']);

        $report = [];
        foreach ($paths as $entry) {
            $metrics = $this->devProfileCollector->collect($entry['path'], $entry['label']);
            $report[] = [
                'path' => $entry['path'],
                'label' => $entry['label'],
                'metrics' => $metrics,
                'issues' => $this->devProfileAnalyzer->analyze($metrics),
            ];
        }

        return $report;
    }

    private function collectPaths(array $onlyPaths): array
    {
        $paths = $this->declaredPaths();
        if (!$onlyPaths) {
            return $paths;
        }

        // A path given by hand keeps the label of the provider that declared it, if any, and is profiled anyway if none did
        $selected = [];
        foreach ($onlyPaths as $path) {
            $selected[$path] = $paths[$path] ?? ['path' => $path, 'label' => null];
        }

        return $selected;
    }

    // Every provider's paths, deduplicated: two bundles declaring the same path (a page reachable through both) must not be profiled twice
    private function declaredPaths(): array
    {
        $paths = [];
        foreach ($this->devProfilePathProviders as $provider) {
            foreach ($provider->getPaths() as $entry) {
                $path = $entry['path'] ?? null;
                if ($path && !isset($paths[$path])) {
                    $paths[$path] = ['path' => $path, 'label' => $entry['label'] ?? null];
                }
            }
        }

        return $paths;
    }
}
