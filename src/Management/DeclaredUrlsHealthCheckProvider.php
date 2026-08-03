<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Attribute\AsHealthCheck;
use c975L\ConfigBundle\Management\HealthCheckFrequencyAwareInterface;
use c975L\ConfigBundle\Management\HealthCheckProviderInterface;
use c975L\ConfigBundle\Management\SitemapProviderInterface;

// Runs the same content-quality checks as ContentQualityHealthCheckProvider (see ContentQualityAnalyzer) over the urls another bundle already declares for its sitemap - a book, a product, a photo, a campaign. Nothing to implement bundle-side: DeclaredUrlsHealthCheckPass registers one of these per SitemapProviderInterface found in the container, so declaring a sitemap is all it takes to be health-checked
class DeclaredUrlsHealthCheckProvider implements HealthCheckProviderInterface, HealthCheckFrequencyAwareInterface
{
    public function __construct(
        private readonly SitemapProviderInterface $sitemapProvider,
        private readonly ContentQualityAnalyzer $contentQualityAnalyzer,
        // One class serves every bundle, so the cadence cannot sit on it: it is read off each bundle's own sitemap provider and handed over here (see DeclaredUrlsHealthCheckPass). Weekly by default - what a site sells or funds is worth catching weekly, a dead product page costs a sale
        private readonly string $frequency = AsHealthCheck::FREQUENCY_WEEKLY,
    ) {
    }

    // The cadence this bundle's urls run at, decided per instance rather than per class - a gallery declaring two thousand photos says so on its own sitemap provider and lands on the monthly entry, while books and products stay weekly
    public function getFrequency(): string
    {
        return $this->frequency;
    }

    // One kind per bundle ("urls-book", "urls-gallery"...), derived from the sitemap it already names, so "c975l:health-check:run --kind=urls-gallery" runs that bundle's urls alone - a gallery declaring two thousand photos has no business running on the same schedule as a handful of pages. They all land on the same Health check dashboard either way
    public function getKind(): string
    {
        return 'urls-' . $this->sitemapProvider->getSitemapName();
    }

    public function runChecks(): array
    {
        $entries = [];
        foreach ($this->sitemapProvider->getUrls() as $url) {
            $location = $url['loc'] ?? null;
            if (!\is_string($location) || '' === $location) {
                continue;
            }

            // No admin screen behind a declared url, so no 'source' to trace an offence back to and no edit link: the label is the url's own path, which is what tells two rows of the same bundle apart on the dashboard
            $entries[] = ['url' => $location, 'label' => $this->labelFromUrl($location), 'editUrl' => null, 'source' => null];
        }

        return $this->contentQualityAnalyzer->analyze($entries);
    }

    private function labelFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, \PHP_URL_PATH), '/');

        return '' === $path ? $url : $path;
    }
}
