<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ShortcutBuilder;
use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use PHPUnit\Framework\TestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class ShortcutBuilderTest extends TestCase
{
    private function createProvider(array $shortcuts): ShortcutProviderInterface
    {
        $provider = $this->createStub(ShortcutProviderInterface::class);
        $provider->method('getShortcuts')->willReturn($shortcuts);

        return $provider;
    }

    public function testGetShortcutsMergesEveryProvider(): void
    {
        $providerA = $this->createProvider([['label' => 'a']]);
        $providerB = $this->createProvider([['label' => 'b']]);
        $builder = new ShortcutBuilder([$providerA, $providerB]);

        $this->assertSame([['label' => 'a'], ['label' => 'b']], $builder->getShortcuts());
    }

    public function testGetShortcutsReturnsEmptyArrayWhenNoProviders(): void
    {
        $builder = new ShortcutBuilder([]);

        $this->assertSame([], $builder->getShortcuts());
    }
}
