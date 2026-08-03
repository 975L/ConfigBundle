<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\UrlStatusChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class UrlStatusCheckerTest extends TestCase
{
    public function testExistsReturnsTrueOn2xx(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('', ['http_code' => 200])
        );

        $checker = new UrlStatusChecker($httpClient);

        $this->assertTrue($checker->exists('https://example.com/pages/home/'));
    }

    public function testExistsReturnsFalseOn404(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('', ['http_code' => 404])
        );

        $checker = new UrlStatusChecker($httpClient);

        $this->assertFalse($checker->exists('https://example.com/pages/missing/'));
    }

    public function testExistsReturnsFalseWhenTheRequestThrows(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => throw new \RuntimeException('DNS failure')
        );

        $checker = new UrlStatusChecker($httpClient);

        $this->assertFalse($checker->exists('https://unresolvable.example/'));
    }

    // The code itself, for a caller that has to report what the url answered rather than only whether it exists
    public function testStatusReturnsTheCode(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => new MockResponse('', ['http_code' => 410])
        );

        $checker = new UrlStatusChecker($httpClient);

        $this->assertSame(410, $checker->status('https://example.com/pages/removed/'));
    }

    // A host that never answered is not a page that isn't there - null tells the two apart, where exists() flattens both into false
    public function testStatusReturnsNullWhenTheRequestThrows(): void
    {
        $httpClient = new MockHttpClient(
            fn (string $method, string $url, array $options) => throw new \RuntimeException('DNS failure')
        );

        $checker = new UrlStatusChecker($httpClient);

        $this->assertNull($checker->status('https://unresolvable.example/'));
    }
}
