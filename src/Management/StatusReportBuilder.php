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
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Composer\InstalledVersions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Kernel;

// Builds the report the c975l:status:send command dumps or sends: what this site runs, and what its health checks last found. Read-only and side-effect free, so it can be dumped as often as wanted. Everything it collects is already known to the site - it aggregates, it never checks anything itself (see HealthCheckProviderInterface for that)
class StatusReportBuilder
{
    // Bumped whenever the payload's shape changes in a way a receiver has to care about, so a console can keep reading older sites while they are being updated
    public const VERSION = 1;

    // Caps the error rows carried by one report. A site with hundreds of broken pages would otherwise send a payload sized by its content rather than by its state - the counts stay exact, only the list is cut, and issuesTruncated says so rather than letting a receiver read a short list as "that's all of them"
    private const MAX_ISSUES = 20;

    public function __construct(
        private readonly iterable $statusProviders,
        private readonly ConfigServiceInterface $configService,
        private readonly HealthCheckResultRepository $healthCheckResultRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    // The whole report, as a json-serializable array
    public function build(): array
    {
        return [
            'version' => self::VERSION,
            'site' => (string) $this->configService->get('site-url'),
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'environment' => $this->environment,
            'php' => \PHP_VERSION,
            'symfony' => Kernel::VERSION,
            'packages' => $this->getPackages(),
            'checks' => $this->getChecks(),
            'extra' => $this->getExtra(),
        ];
    }

    // The bundles this site runs, and their versions. Bundles rather than dependencies: listing what the application requires meant a few dozen lines where "symfony/mime" sat next to EasyAdmin, and no maintainer ever compares those across sites. Whether a bundle is a direct requirement or came with another one doesn't matter here - what is installed is what runs
    private function getPackages(): array
    {
        $packages = [];

        foreach (InstalledVersions::getInstalledPackagesByType('symfony-bundle') as $name) {
            // The framework's own bundles all carry the Symfony version, already reported as its own field
            if (str_starts_with($name, 'symfony/')) {
                continue;
            }

            $packages[$name] = InstalledVersions::getPrettyVersion($name);
        }

        ksort($packages);

        return $packages;
    }

    // What the last health check run found, as counts plus the rows in error. HealthCheckResult::$details is left out on purpose: it holds the checkers' raw payloads, which is what makes a row actionable but also what makes it big and occasionally revealing - the receiver gets to know where it hurts, the site keeps why
    private function getChecks(): ?array
    {
        try {
            $rows = $this->healthCheckResultRepository->findLatestPerUrlAndKind();
        } catch (\Throwable) {
            // A site with the bundle installed but its migrations not run yet still has a version and a package list worth reporting - the absence of the section says the checks are unavailable, which is not the same as "no issue found"
            return null;
        }

        $counts = array_fill_keys(HealthCheckResult::STATUSES, 0);
        $issues = [];
        $lastRunAt = null;

        foreach ($rows as $row) {
            ++$counts[$row->getStatus()];

            if (null === $lastRunAt || $row->getCheckedAt() > $lastRunAt) {
                $lastRunAt = $row->getCheckedAt();
            }

            // Errors only: a site in warning is a site to improve, and its own dashboard already lists where. What has to travel is what needs acting on today - the warning count still shows in "counts", so a site drifting is visible without carrying every row it drifted on
            if (HealthCheckResult::STATUS_ERROR === $row->getStatus()) {
                $issues[] = [
                    'kind' => $row->getKind(),
                    'url' => $row->getUrl(),
                    'summary' => $row->getSummary(),
                ];
            }
        }

        return [
            'counts' => $counts,
            'lastRunAt' => $lastRunAt?->format(\DateTimeInterface::ATOM),
            'issues' => \array_slice($issues, 0, self::MAX_ISSUES),
            'issuesTruncated' => \count($issues) > self::MAX_ISSUES,
        ];
    }

    // What the installed bundles chose to add, one section per provider (see StatusProviderInterface). A provider that throws must not cost the whole report: the site would then look silent to a receiver, which reads as a much worse problem than the one section that failed
    private function getExtra(): \stdClass
    {
        $extra = [];

        foreach ($this->statusProviders as $provider) {
            // Resolved first so the catch never has to call getStatusKey() again: were that the throwing call, naming it a second time would rethrow past the catch and cost the whole report the comment above promises to keep
            $key = $provider::class;

            try {
                $key = $provider->getStatusKey();
                $extra[$key] = $provider->getStatusData();
            } catch (\Throwable $e) {
                $extra[$key] = ['error' => $e->getMessage()];
            }
        }

        // Returned as an object rather than an array: a site with no provider at all - the common case - would otherwise send "extra": [], and a receiver reading it as a keyed structure breaks on the one payload shape it will meet most often
        return (object) $extra;
    }
}
