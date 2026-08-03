<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\DeploymentClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DeploymentClientTest extends TestCase
{
    public function testFetchWithoutRedirectReturnsTheStatusAndTheTarget(): void
    {
        $client = new DeploymentClient(new MockHttpClient(
            fn () => new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => 'https://example.com/']])
        ));

        $this->assertSame(
            ['statusCode' => 301, 'location' => 'https://example.com/'],
            $client->fetchWithoutRedirect('http://example.com/')
        );
    }

    // The whole point of the call: the redirect is the answer, so following it would report the https page instead
    public function testFetchWithoutRedirectDoesNotFollowTheRedirect(): void
    {
        $options = null;
        $client = new DeploymentClient(new MockHttpClient(
            function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
                $options = $requestOptions;

                return new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => 'https://example.com/']]);
            }
        ));

        $client->fetchWithoutRedirect('http://example.com/');

        $this->assertSame(0, $options['max_redirects']);
    }

    // A site answering http:// directly, with no redirect at all - nothing to report as a location
    public function testFetchWithoutRedirectReturnsANullLocationWhenThereIsNoRedirect(): void
    {
        $client = new DeploymentClient(new MockHttpClient(fn () => new MockResponse('<html></html>', ['http_code' => 200])));

        $this->assertSame(
            ['statusCode' => 200, 'location' => null],
            $client->fetchWithoutRedirect('http://example.com/')
        );
    }

    public function testFetchReturnsTheStatusAndTheBody(): void
    {
        $client = new DeploymentClient(new MockHttpClient(
            fn () => new MockResponse('<html><body>Page not found</body></html>', ['http_code' => 404])
        ));

        $this->assertSame(
            ['statusCode' => 404, 'content' => '<html><body>Page not found</body></html>'],
            $client->fetch('https://example.com/c975l-health-check-404-probe')
        );
    }

    // The 404 probe's expected answer is an error status, so the body must come back rather than throw on it
    public function testFetchReturnsTheBodyOfAnErrorResponse(): void
    {
        $client = new DeploymentClient(new MockHttpClient(
            fn () => new MockResponse('<html><body>Server error</body></html>', ['http_code' => 500])
        ));

        $result = $client->fetch('https://example.com/c975l-health-check-404-probe');

        $this->assertSame(500, $result['statusCode']);
        $this->assertSame('<html><body>Server error</body></html>', $result['content']);
    }
}
