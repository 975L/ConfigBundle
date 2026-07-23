<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\DashboardWidgetBuilder;
use c975L\ConfigBundle\Management\DashboardWidgetProviderInterface;
use PHPUnit\Framework\TestCase;

class DashboardWidgetBuilderTest extends TestCase
{
    private function createProvider(array $widgets): DashboardWidgetProviderInterface
    {
        $provider = $this->createStub(DashboardWidgetProviderInterface::class);
        $provider->method('getDashboardWidgets')->willReturn($widgets);

        return $provider;
    }

    public function testGetWidgetsMergesAcrossProviders(): void
    {
        $providerA = $this->createProvider([['template' => '@a/widget.html.twig', 'context' => []]]);
        $providerB = $this->createProvider([]);
        $builder = new DashboardWidgetBuilder([$providerA, $providerB]);

        $this->assertSame([['template' => '@a/widget.html.twig', 'context' => []]], $builder->getWidgets());
    }

    public function testGetWidgetsIsEmptyWhenNoProviderContributesAnything(): void
    {
        $builder = new DashboardWidgetBuilder([$this->createProvider([]), $this->createProvider([])]);

        $this->assertSame([], $builder->getWidgets());
    }
}
