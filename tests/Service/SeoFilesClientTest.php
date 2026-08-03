<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\SeoFilesClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SeoFilesClientTest extends TestCase
{
    public function testFetchReturnsStatusCodeAndContent(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse("User-agent: *\nDisallow:", ['http_code' => 200])
        );

        $client = new SeoFilesClient($httpClient);
        $file = $client->fetch('https://example.com/robots.txt');

        $this->assertSame(200, $file['statusCode']);
        $this->assertSame("User-agent: *\nDisallow:", $file['content']);
    }

    // What tells a sitemap nobody regenerates from one whose content is simply stable - the file's own write date, which its <lastmod>s (each Page's modification date) never carry
    public function testFetchReturnsTheLastModifiedHeaderAsADate(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('<urlset/>', ['response_headers' => ['Last-Modified' => 'Wed, 22 Jul 2026 08:30:00 GMT']])
        );

        $client = new SeoFilesClient($httpClient);
        $file = $client->fetch('https://example.com/sitemap-site.xml');

        $this->assertInstanceOf(\DateTimeImmutable::class, $file['lastModified']);
        $this->assertSame('2026-07-22 08:30:00', $file['lastModified']->setTimezone(new \DateTimeZone('GMT'))->format('Y-m-d H:i:s'));
    }

    // A sitemap served by a controller rather than written to disk carries no such header - saying nothing about its freshness, which is not the same as being stale
    public function testFetchReturnsANullLastModifiedWhenTheHeaderIsAbsent(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('<urlset/>')
        );

        $client = new SeoFilesClient($httpClient);

        $this->assertNull($client->fetch('https://example.com/sitemap-site.xml')['lastModified']);
    }

    public function testFetchReturnsANullLastModifiedWhenTheHeaderIsUnparsable(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('<urlset/>', ['response_headers' => ['Last-Modified' => 'not a date']])
        );

        $client = new SeoFilesClient($httpClient);

        $this->assertNull($client->fetch('https://example.com/sitemap-site.xml')['lastModified']);
    }

    public function testFetchDoesNotThrowOnANonSuccessStatusCode(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('Not Found', ['http_code' => 404])
        );

        $client = new SeoFilesClient($httpClient);
        $file = $client->fetch('https://example.com/robots.txt');

        $this->assertSame(404, $file['statusCode']);
    }

    public function testFetchPropagatesTransportExceptions(): void
    {
        $this->expectException(TransportException::class);

        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('', ['error' => 'Connection refused'])
        );

        $client = new SeoFilesClient($httpClient);
        $client->fetch('https://example.com/robots.txt');
    }
}
