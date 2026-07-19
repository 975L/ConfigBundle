<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\DependencyInjection\Compiler;

use c975L\ConfigBundle\DependencyInjection\Compiler\TaggedInterfacePass;
use c975L\ConfigBundle\Management\AlertProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class TaggedInterfacePassTest extends TestCase
{
    public function testProcessTagsOnlyServicesImplementingTheGivenInterface(): void
    {
        $container = new ContainerBuilder();
        $container->register('matching', TaggedInterfacePassFixtureProvider::class);
        $container->register('non_matching', TaggedInterfacePassFixtureOther::class);

        (new TaggedInterfacePass(AlertProviderInterface::class, 'c975l_config.alert_provider'))->process($container);

        $this->assertTrue($container->getDefinition('matching')->hasTag('c975l_config.alert_provider'));
        $this->assertFalse($container->getDefinition('non_matching')->hasTag('c975l_config.alert_provider'));
    }

    public function testProcessSkipsDefinitionsWithoutAClass(): void
    {
        $container = new ContainerBuilder();
        $definition = $container->register('no_class');
        $definition->setClass(null);

        (new TaggedInterfacePass(AlertProviderInterface::class, 'c975l_config.alert_provider'))->process($container);

        $this->assertFalse($container->getDefinition('no_class')->hasTag('c975l_config.alert_provider'));
    }

    // A definition pointing at a class that doesn't exist (e.g. a require-dev-only package missing in prod) must not break the pass nor be tagged
    public function testProcessIgnoresDefinitionsWithAnUnresolvableClassWithoutThrowing(): void
    {
        $container = new ContainerBuilder();
        $container->register('unresolvable', 'This\\Class\\Does\\Not\\Exist');

        (new TaggedInterfacePass(AlertProviderInterface::class, 'c975l_config.alert_provider'))->process($container);

        $this->assertFalse($container->getDefinition('unresolvable')->hasTag('c975l_config.alert_provider'));
    }
}
