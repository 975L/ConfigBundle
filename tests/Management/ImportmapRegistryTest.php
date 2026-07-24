<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ImportmapProviderInterface;
use c975L\ConfigBundle\Management\ImportmapRegistry;
use PHPUnit\Framework\TestCase;

class ImportmapRegistryTest extends TestCase
{
    private function createProvider(array $adminEntries, array $entries = []): ImportmapProviderInterface
    {
        $provider = $this->createStub(ImportmapProviderInterface::class);
        $provider->method('getAdminImportmapEntries')->willReturn($adminEntries);
        $provider->method('getImportmapEntries')->willReturn($entries);

        return $provider;
    }

    public function testAllReturnsEveryAdminEntryMergedAcrossProviders(): void
    {
        $providerA = $this->createProvider([
            '@c975l/config-bundle/controllers-admin.js' => ['path' => './vendor/c975l/config-bundle/assets/controllers-admin.js', 'entrypoint' => true],
        ]);
        $providerB = $this->createProvider([
            '@c975l/ui-bundle/controllers.js' => ['path' => './vendor/c975l/ui-bundle/assets/controllers.js', 'entrypoint' => true],
        ]);
        $registry = new ImportmapRegistry([$providerA, $providerB]);

        $this->assertSame([
            '@c975l/config-bundle/controllers-admin.js' => ['path' => './vendor/c975l/config-bundle/assets/controllers-admin.js', 'entrypoint' => true],
            '@c975l/ui-bundle/controllers.js' => ['path' => './vendor/c975l/ui-bundle/assets/controllers.js', 'entrypoint' => true],
        ], $registry->all());
    }

    public function testAllMergesAdminAndNonAdminEntriesFromTheSameProvider(): void
    {
        $provider = $this->createProvider(
            ['@c975l/config-bundle/controllers-admin.js' => ['path' => './vendor/c975l/config-bundle/assets/controllers-admin.js', 'entrypoint' => true]],
            ['@c975l/config-bundle/controllers.js' => ['path' => './vendor/c975l/config-bundle/assets/controllers.js', 'entrypoint' => true]],
        );
        $registry = new ImportmapRegistry([$provider]);

        $this->assertSame([
            '@c975l/config-bundle/controllers-admin.js' => ['path' => './vendor/c975l/config-bundle/assets/controllers-admin.js', 'entrypoint' => true],
            '@c975l/config-bundle/controllers.js' => ['path' => './vendor/c975l/config-bundle/assets/controllers.js', 'entrypoint' => true],
        ], $registry->all());
    }

    public function testAllReturnsEmptyArrayWhenNoProvider(): void
    {
        $registry = new ImportmapRegistry([]);

        $this->assertSame([], $registry->all());
    }
}
