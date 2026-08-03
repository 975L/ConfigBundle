<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\SecurityHeadersClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SecurityHeadersClientTest extends TestCase
{
    public function testFetchHeadersLowercasesHeaderNamesAndKeepsTheFirstValue(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('', [
                'http_code' => 200,
                'response_headers' => [
                    'Strict-Transport-Security' => 'max-age=31536000',
                    'X-Content-Type-Options' => 'nosniff',
                ],
            ])
        );

        $client = new SecurityHeadersClient($httpClient);
        $headers = $client->fetchHeaders('https://example.com/pages/home/');

        $this->assertSame('max-age=31536000', $headers['strict-transport-security']);
        $this->assertSame('nosniff', $headers['x-content-type-options']);
    }

    public function testFetchHeadersDoesNotThrowOnANonSuccessStatusCode(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('', [
                'http_code' => 404,
                'response_headers' => ['X-Content-Type-Options' => 'nosniff'],
            ])
        );

        $client = new SecurityHeadersClient($httpClient);
        $headers = $client->fetchHeaders('https://example.com/pages/missing/');

        $this->assertSame('nosniff', $headers['x-content-type-options']);
    }

    public function testFetchHeadersPropagatesTransportExceptions(): void
    {
        $this->expectException(TransportException::class);

        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('', ['error' => 'Connection refused'])
        );

        $client = new SecurityHeadersClient($httpClient);
        $client->fetchHeaders('https://example.com/pages/home/');
    }
}
