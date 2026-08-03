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

// Reads a page's own HTTP response headers - no securityheaders.com scraping (no public API for automated use), the same checks it runs are cheap to reimplement directly against the site's real response. Used by SecurityHeadersHealthCheckProvider, only ever from the c975l:health-check:run command
class SecurityHeadersClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    // Lowercased header name => first value. Reads headers only (no body buffering) - throws only on a real network/transport failure, not on a non-2xx status (a page returning e.g. a 404 still has headers worth checking)
    public function fetchHeaders(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 30, 'buffer' => false]);

        $headers = [];
        foreach ($response->getHeaders(false) as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }

        return $headers;
    }
}
