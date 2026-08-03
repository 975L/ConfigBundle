<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

// Cheap existence check (single HEAD request) before a slower page-content check (W3C validators, content-quality analysis) spends a call on a url that doesn't resolve - a page present in a lower environment's database but never deployed to the checked url (see PagePublicUrlResolver/"site-url") 404s here, which those checks would otherwise report as a confusing raw HTTP error. Shared by every HealthCheckProviderInterface implementation that needs it, rather than each reimplementing the same HEAD request
class UrlStatusChecker
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function exists(string $url): bool
    {
        $status = $this->status($url);

        return null !== $status && $status < 400;
    }

    // The url's own status code, or null when the request never completed (timeout, DNS, refused connection) - "the page answers 404" and "the host didn't answer at all" are two different verdicts to report, and exists() flattens both into the same false (see ContentQualityAnalyzer, which needs the code itself)
    public function status(string $url): ?int
    {
        try {
            return $this->httpClient->request('HEAD', $url, [
                'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; c975l-health-check)'],
                'timeout' => 15,
            ])->getStatusCode();
        } catch (\Throwable) {
            return null;
        }
    }
}
