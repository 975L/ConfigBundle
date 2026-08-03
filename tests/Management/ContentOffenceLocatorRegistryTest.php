<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ContentOffenceLocatorInterface;
use c975L\ConfigBundle\Management\ContentOffenceLocatorRegistry;
use PHPUnit\Framework\TestCase;

class ContentOffenceLocatorRegistryTest extends TestCase
{
    // A locator recognizing only the given class of source, answering with the screen holding it
    private function createLocator(string $supportedClass, ?array $image = null, ?array $link = null): ContentOffenceLocatorInterface
    {
        $locator = $this->createStub(ContentOffenceLocatorInterface::class);
        $locator->method('supports')->willReturnCallback(static fn (object $source) => $source instanceof $supportedClass);
        $locator->method('locateImage')->willReturn($image);
        $locator->method('locateLink')->willReturn($link);

        return $locator;
    }

    public function testLocateImageAnswersWithTheScreenTheFirstSupportingLocatorNames(): void
    {
        $registry = new ContentOffenceLocatorRegistry([
            $this->createLocator(\ArrayObject::class, ['label' => 'Never asked', 'editUrl' => '/never']),
            $this->createLocator(\stdClass::class, ['label' => 'Hero block', 'editUrl' => '/admin/block/12']),
        ]);

        $this->assertSame(
            ['label' => 'Hero block', 'editUrl' => '/admin/block/12'],
            $registry->locateImage(new \stdClass(), '/media/hero.png')
        );
    }

    public function testLocateLinkAnswersWithTheScreenTheFirstSupportingLocatorNames(): void
    {
        $registry = new ContentOffenceLocatorRegistry([
            $this->createLocator(\stdClass::class, link: ['label' => 'Footer block', 'editUrl' => '/admin/block/34']),
        ]);

        $this->assertSame(
            ['label' => 'Footer block', 'editUrl' => '/admin/block/34'],
            $registry->locateLink(new \stdClass(), 'https://example.com/gone')
        );
    }

    // An offence on an entry carrying no source at all: still reported by the analyzer, just without a link
    public function testLocatingWithoutASourceAsksNoLocator(): void
    {
        $locator = $this->createMock(ContentOffenceLocatorInterface::class);
        $locator->expects($this->never())->method('supports');

        $registry = new ContentOffenceLocatorRegistry([$locator]);

        $this->assertNull($registry->locateImage(null, '/media/hero.png'));
        $this->assertNull($registry->locateLink(null, 'https://example.com/gone'));
    }

    // A source no installed bundle claims (a theme image, a url with no admin screen behind it)
    public function testLocatingAnUnclaimedSourceReturnsNull(): void
    {
        $registry = new ContentOffenceLocatorRegistry([$this->createLocator(\ArrayObject::class)]);

        $this->assertNull($registry->locateImage(new \stdClass(), '/media/hero.png'));
        $this->assertNull($registry->locateLink(new \stdClass(), 'https://example.com/gone'));
    }

    // The default on a site whose bundles trace nothing back
    public function testAnEmptyRegistryLocatesNothing(): void
    {
        $registry = new ContentOffenceLocatorRegistry();

        $this->assertNull($registry->locateImage(new \stdClass(), '/media/hero.png'));
        $this->assertNull($registry->locateLink(new \stdClass(), 'https://example.com/gone'));
    }
}
