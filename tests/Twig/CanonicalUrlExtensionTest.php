<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Twig\CanonicalUrlExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class CanonicalUrlExtensionTest extends TestCase
{
    private function createExtension(?string $requestUri, ?string $siteUrl = 'https://975l.com'): CanonicalUrlExtension
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['site-url', $siteUrl],
        ]);

        $requestStack = new RequestStack();
        if (null !== $requestUri) {
            $requestStack->push(Request::create($requestUri));
        }

        return new CanonicalUrlExtension($configService, $requestStack);
    }

    public function testCanonicalUrlOfAPageIsTheConfiguredHostPlusItsPath(): void
    {
        $extension = $this->createExtension('https://975l.com/pages/blocks');

        $this->assertSame('https://975l.com/pages/blocks', $extension->getCanonicalUrl());
    }

    public function testCanonicalUrlDropsTheQueryString(): void
    {
        $extension = $this->createExtension('https://975l.com/pages/blocks?fbclid=abc&utm_source=newsletter');

        $this->assertSame('https://975l.com/pages/blocks', $extension->getCanonicalUrl());
    }

    public function testCanonicalUrlDropsTheTrailingSlash(): void
    {
        $extension = $this->createExtension('https://975l.com/pages/blocks/');

        $this->assertSame('https://975l.com/pages/blocks', $extension->getCanonicalUrl());
    }

    public function testCanonicalUrlOfTheSiteRootKeepsItsSlash(): void
    {
        $extension = $this->createExtension('https://975l.com/');

        $this->assertSame('https://975l.com/', $extension->getCanonicalUrl());
    }

    // A "collection" block's item detail has a path of its own, and must keep it rather than resolve to the Page serving it
    public function testCanonicalUrlOfACollectionItemDetailKeepsItsOwnPath(): void
    {
        $extension = $this->createExtension('https://975l.com/pages/blocks-details/mon-item');

        $this->assertSame('https://975l.com/pages/blocks-details/mon-item', $extension->getCanonicalUrl());
    }

    // Whichever host and scheme the visitor came through, the canonical is the site's own - "www" and http variants must not each declare themselves canonical
    public function testCanonicalUrlUsesTheConfiguredHostRatherThanTheRequestedOne(): void
    {
        $extension = $this->createExtension('http://www.975l.com/pages/blocks');

        $this->assertSame('https://975l.com/pages/blocks', $extension->getCanonicalUrl());
    }

    public function testCanonicalUrlIsNullWithoutARequest(): void
    {
        $extension = $this->createExtension(null);

        $this->assertNull($extension->getCanonicalUrl());
    }

    public function testCanonicalUrlIsNullWhenSiteUrlIsNotConfigured(): void
    {
        $extension = $this->createExtension('https://975l.com/pages/blocks', null);

        $this->assertNull($extension->getCanonicalUrl());
    }

    public function testCanonicalUrlToleratesASiteUrlWithATrailingSlash(): void
    {
        $extension = $this->createExtension('https://975l.com/pages/blocks', 'https://975l.com/');

        $this->assertSame('https://975l.com/pages/blocks', $extension->getCanonicalUrl());
    }
}
