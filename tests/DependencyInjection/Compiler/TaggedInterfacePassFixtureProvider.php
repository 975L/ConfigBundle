<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\DependencyInjection\Compiler;

use c975L\ConfigBundle\Management\AlertProviderInterface;

// Fixture implementing AlertProviderInterface, used by TaggedInterfacePassTest to verify the pass
// tags matching services. Its own file (not inlined in the test class) - src/Tests classes are
// autoloadable by consuming apps, whose attribute route loader recursively reflects every class
// under the bundle root, and PSR-4 requires one file per class for that to work.
class TaggedInterfacePassFixtureProvider implements AlertProviderInterface
{
    public function getAlerts(): array
    {
        return [];
    }
}
