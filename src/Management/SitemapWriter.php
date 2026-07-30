<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

// Writes one public/sitemap-<name>.xml per registered SitemapProviderInterface, plus the sitemap-index.xml declaring them all - so a bundle with public urls only ever supplies urls, and never renders or writes a sitemap itself. Lives here rather than in SiteBundle so any combination of bundles gets its sitemaps, SiteBundle installed or not (same reason HealthCheckRunner is here)
class SitemapWriter
{
    // Applied to an url a provider left incomplete, so a missing key never produces an empty element
    private const DEFAULT_CHANGEFREQ = 'weekly';
    // On the providers' own 0-10 scale, so 5 ends up as the 0.5 the protocol gets
    private const DEFAULT_PRIORITY = 5;
    private const PRIORITY_SCALE = 10;

    private string $sitemapFolder;

    private Filesystem $filesystem;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly Environment $environment,
        // Every SitemapProviderInterface implementation, whatever the bundle it comes from - tagged automatically by TaggedInterfacePass, so nothing has to be listed by hand in the app (see services.yaml)
        private readonly iterable $sitemapProviders,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->sitemapFolder = $projectDir . '/public';
        $this->filesystem = new Filesystem();
    }

    // Creates every contributed sub-sitemap and the index declaring them, and returns the names written
    public function write(): array
    {
        $names = [];
        $declaredNames = [];
        foreach ($this->sitemapProviders as $provider) {
            /** @var SitemapProviderInterface $provider */
            $name = $provider->getSitemapName();

            // Two providers sharing a name would overwrite each other's file and declare the same url twice in the index - a naming mistake to fix, not something to silently absorb
            if (in_array($name, $declaredNames, true)) {
                throw new \LogicException(sprintf('The sitemap name "%s" is declared by more than one SitemapProviderInterface, each one must use its own.', $name));
            }
            $declaredNames[] = $name;

            $urls = $provider->getUrls();
            $sitemapFile = $this->sitemapFolder . '/sitemap-' . $name . '.xml';

            // A provider with nothing to declare (a bundle installed but not used yet) gets no file at all, rather than an empty urlset the index would point at, and any file left by a previous run is removed so nothing stale keeps being served
            if ([] === $urls) {
                $this->filesystem->remove($sitemapFile);

                continue;
            }

            $sitemapContent = $this->environment->render('@c975LConfig/sitemaps/sitemap.xml.twig', ['urls' => $this->normalizeUrls($urls)]);
            $this->filesystem->dumpFile($sitemapFile, $sitemapContent);
            $names[] = $name;
        }

        $this->writeIndex($names);

        return $names;
    }

    // Creates the index declaring the sub-sitemaps just written
    public function writeIndex(array $names): void
    {
        // Nothing was written (a brand new site), so there's nothing to declare. A sitemap index only accepts absolute urls too, so there's nothing to write before "site-url" is configured either
        $urlRoot = rtrim((string) $this->configService->get('site-url'), '/');
        if ([] === $names || '' === $urlRoot) {
            return;
        }

        $sitemaps = array_map(fn (string $name): string => $urlRoot . '/sitemap-' . $name . '.xml', $names);
        $sitemapIndexContent = $this->environment->render('@c975LConfig/sitemaps/sitemap-index.xml.twig', ['sitemaps' => $sitemaps]);
        $this->filesystem->dumpFile($this->sitemapFolder . '/sitemap-index.xml', $sitemapIndexContent);
    }

    // Fills in the keys a provider left out, and converts its 0-10 "priority" to the 0.0-1.0 the protocol accepts, bounded - so an incomplete or out of range url still produces a valid sitemap, and every provider keeps declaring priorities on the same admin-facing scale as Page::$priority
    private function normalizeUrls(array $urls): array
    {
        return array_map(fn (array $url): array => [
            'loc' => $url['loc'],
            'lastmod' => $url['lastmod'] ?? date('Y-m-d'),
            'changefreq' => $url['changefreq'] ?? self::DEFAULT_CHANGEFREQ,
            'priority' => min(1.0, max(0.0, (float) ($url['priority'] ?? self::DEFAULT_PRIORITY) / self::PRIORITY_SCALE)),
        ], $urls);
    }
}
