<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ConfigImportmapProvider;
use PHPUnit\Framework\TestCase;

class ConfigImportmapProviderTest extends TestCase
{
    public function testGetAdminImportmapEntriesReturnsControllersAdminEntrypoint(): void
    {
        $entries = (new ConfigImportmapProvider())->getAdminImportmapEntries();

        $this->assertSame([
            '@c975l/config-bundle/controllers-admin.js' => [
                'path' => './vendor/c975l/config-bundle/assets/controllers-admin.js',
                'entrypoint' => true,
            ],
        ], $entries);
    }

    public function testGetImportmapEntriesReturnsNoneYet(): void
    {
        $this->assertSame([], (new ConfigImportmapProvider())->getImportmapEntries());
    }
}
