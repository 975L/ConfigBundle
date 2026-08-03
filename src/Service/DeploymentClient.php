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

// The two raw HTTP calls DeploymentHealthCheckProvider needs, kept apart from SeoFilesClient because one of them is the opposite of a normal fetch: it must *not* follow the redirect it's asking about
class DeploymentClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    // max_redirects: 0, so the redirect itself is the answer rather than the https page already known to work
    // @return array{statusCode: int, location: ?string}
    public function fetchWithoutRedirect(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 15, 'max_redirects' => 0]);

        return ['statusCode' => $response->getStatusCode(), 'location' => $response->getHeaders(false)['location'][0] ?? null];
    }

    // A GET, not a HEAD: the body is needed to tell the site's error page from the framework's default
    // @return array{statusCode: int, content: string}
    public function fetch(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 15]);

        return ['statusCode' => $response->getStatusCode(), 'content' => $response->getContent(false)];
    }
}
