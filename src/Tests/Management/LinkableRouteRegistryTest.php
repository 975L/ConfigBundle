<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\LinkableRouteProviderInterface;
use c975L\ConfigBundle\Management\LinkableRouteRegistry;
use PHPUnit\Framework\TestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class LinkableRouteRegistryTest extends TestCase
{
    private function createProvider(array $routes): LinkableRouteProviderInterface
    {
        $provider = $this->createStub(LinkableRouteProviderInterface::class);
        $provider->method('getLinkableRoutes')->willReturn($routes);

        return $provider;
    }

    public function testHasAndGetReflectRoutesMergedAcrossProviders(): void
    {
        $providerA = $this->createProvider(['contact_index' => ['label' => 'label.contact', 'translation_domain' => 'contact']]);
        $providerB = $this->createProvider(['shop_index' => ['label' => 'label.shop', 'translation_domain' => 'shop']]);
        $registry = new LinkableRouteRegistry([$providerA, $providerB]);

        $this->assertTrue($registry->has('contact_index'));
        $this->assertTrue($registry->has('shop_index'));
        $this->assertSame(['label' => 'label.contact', 'translation_domain' => 'contact'], $registry->get('contact_index'));
    }

    public function testHasReturnsFalseAndGetReturnsNullForUnknownRoute(): void
    {
        $registry = new LinkableRouteRegistry([$this->createProvider([])]);

        $this->assertFalse($registry->has('unknown_route'));
        $this->assertNull($registry->get('unknown_route'));
    }

    public function testAllReturnsEveryMergedRoute(): void
    {
        $providerA = $this->createProvider(['route-a' => ['label' => 'a']]);
        $providerB = $this->createProvider(['route-b' => ['label' => 'b']]);
        $registry = new LinkableRouteRegistry([$providerA, $providerB]);

        $this->assertSame([
            'route-a' => ['label' => 'a'],
            'route-b' => ['label' => 'b'],
        ], $registry->all());
    }
}
