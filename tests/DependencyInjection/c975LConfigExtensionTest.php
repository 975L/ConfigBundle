<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\DependencyInjection;

use c975L\ConfigBundle\DependencyInjection\c975LConfigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class c975LConfigExtensionTest extends TestCase
{
    // load() is currently a no-op: it must not register any definition nor throw
    public function testLoadDoesNotAlterTheContainer(): void
    {
        $container = new ContainerBuilder();
        $definitionsBefore = $container->getDefinitions();

        (new c975LConfigExtension())->load([], $container);

        $this->assertSame($definitionsBefore, $container->getDefinitions());
    }
}
