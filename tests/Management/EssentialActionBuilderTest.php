<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\EssentialActionBuilder;
use c975L\ConfigBundle\Management\EssentialActionProviderInterface;
use PHPUnit\Framework\TestCase;

class EssentialActionBuilderTest extends TestCase
{
    // Builds an EssentialActionProviderInterface double returning the given actions
    private function createProvider(array $actions): EssentialActionProviderInterface
    {
        $provider = $this->createStub(EssentialActionProviderInterface::class);
        $provider->method('getEssentialActions')->willReturn($actions);

        return $provider;
    }

    private function createAction(string $slug, int $order, bool $isDone): array
    {
        return [
            'slug' => $slug,
            'label' => 'label.' . $slug,
            'description' => null,
            'translation_domain' => 'config',
            'url' => '/x',
            'isDone' => $isDone,
            'order' => $order,
        ];
    }

    public function testGetActionsMergesProvidersAndSortsByOrder(): void
    {
        $providerA = $this->createProvider([$this->createAction('third', 30, false)]);
        $providerB = $this->createProvider([
            $this->createAction('first', 10, true),
            $this->createAction('second', 20, false),
        ]);
        $builder = new EssentialActionBuilder([$providerA, $providerB]);

        $actions = $builder->getActions();

        $this->assertSame(['first', 'second', 'third'], array_column($actions, 'slug'));
    }

    public function testGetProgressCountsDoneAgainstTotal(): void
    {
        $provider = $this->createProvider([
            $this->createAction('a', 10, true),
            $this->createAction('b', 20, false),
            $this->createAction('c', 30, true),
        ]);
        $builder = new EssentialActionBuilder([$provider]);

        $this->assertSame(['done' => 2, 'total' => 3], $builder->getProgress());
    }

    public function testGetProgressWithNoProvidersIsZeroOfZero(): void
    {
        $builder = new EssentialActionBuilder([]);

        $this->assertSame(['done' => 0, 'total' => 0], $builder->getProgress());
    }
}
