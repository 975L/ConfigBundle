<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ProcedureBuilder;
use c975L\ConfigBundle\Management\ProcedureProviderInterface;
use PHPUnit\Framework\TestCase;

class ProcedureBuilderTest extends TestCase
{
    private function createProvider(array $procedures): ProcedureProviderInterface
    {
        $provider = $this->createStub(ProcedureProviderInterface::class);
        $provider->method('getProcedures')->willReturn($procedures);

        return $provider;
    }

    public function testGetAllMergesEveryProviderSortedBySlug(): void
    {
        $providerA = $this->createProvider([['slug' => 'creer-formulaire', 'title' => 'a', 'body' => 'a']]);
        $providerB = $this->createProvider([['slug' => 'creer-page', 'title' => 'b', 'body' => 'b']]);
        $builder = new ProcedureBuilder([$providerA, $providerB]);

        $this->assertSame(
            [
                ['slug' => 'creer-formulaire', 'title' => 'a', 'body' => 'a'],
                ['slug' => 'creer-page', 'title' => 'b', 'body' => 'b'],
            ],
            $builder->getAll(),
        );
    }

    public function testGetAllReturnsEmptyArrayWhenNoProviders(): void
    {
        $builder = new ProcedureBuilder([]);

        $this->assertSame([], $builder->getAll());
    }
}
