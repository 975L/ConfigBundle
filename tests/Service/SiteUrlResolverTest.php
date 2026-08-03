<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use PHPUnit\Framework\TestCase;

class SiteUrlResolverTest extends TestCase
{
    private function createResolver(mixed $siteUrl): SiteUrlResolver
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($siteUrl);

        return new SiteUrlResolver($configService);
    }

    public function testSiteUrlReturnsTheConfiguredHost(): void
    {
        $this->assertSame('https://example.com', $this->createResolver('https://example.com')->siteUrl());
    }

    // Every caller appends a path already opening with a slash, so keeping the configured one would double it
    public function testSiteUrlDropsTheTrailingSlash(): void
    {
        $this->assertSame('https://example.com', $this->createResolver('https://example.com/')->siteUrl());
    }

    public function testSiteUrlTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('https://example.com', $this->createResolver("  https://example.com  ")->siteUrl());
    }

    // Null rather than an empty string, so a caller can tell "not configured yet" from a value it could work with
    public function testSiteUrlIsNullWhileTheConfigIsUnset(): void
    {
        $this->assertNull($this->createResolver(null)->siteUrl());
        $this->assertNull($this->createResolver('')->siteUrl());
        $this->assertNull($this->createResolver('   ')->siteUrl());
    }

    // The spelling every site-wide check groups its dashboard row on
    public function testSiteRootAlwaysEndsWithExactlyOneSlash(): void
    {
        $this->assertSame('https://example.com/', $this->createResolver('https://example.com')->siteRoot());
        $this->assertSame('https://example.com/', $this->createResolver('https://example.com/')->siteRoot());
    }

    public function testSiteRootIsNullWhileTheConfigIsUnset(): void
    {
        $this->assertNull($this->createResolver('')->siteRoot());
    }
}
