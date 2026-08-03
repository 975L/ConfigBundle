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
use Symfony\Contracts\HttpClient\ResponseInterface;

// Fetches robots.txt/sitemap-site.xml's own content for SeoFilesHealthCheckProvider - a plain GET (not HEAD), since both need their body inspected (robots.txt for a blanket "Disallow: /", the sitemap for well-formed XML), not just a status code
class SeoFilesClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    // @return array{statusCode: int, content: string, lastModified: ?\DateTimeImmutable}
    public function fetch(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 15]);

        return [
            'statusCode' => $response->getStatusCode(),
            'content' => $response->getContent(false),
            'lastModified' => $this->readLastModified($response),
        ];
    }

    // When the file itself was last written, read off the Last-Modified header the web server sends for a static file - null when the header is absent (a sitemap served by a controller rather than written to disk) or unparsable, which says nothing about its freshness rather than saying it is stale. strtotime() rather than a single format, since the three date shapes HTTP allows are all legal here
    private function readLastModified(ResponseInterface $response): ?\DateTimeImmutable
    {
        $header = $response->getHeaders(false)['last-modified'][0] ?? null;
        if (null === $header) {
            return null;
        }

        $timestamp = strtotime($header);

        return false === $timestamp ? null : new \DateTimeImmutable('@' . $timestamp);
    }
}
