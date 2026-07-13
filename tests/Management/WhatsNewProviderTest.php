<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\WhatsNewJsonReader;
use c975L\ConfigBundle\Management\WhatsNewProvider;
use PHPUnit\Framework\TestCase;

class WhatsNewProviderTest extends TestCase
{
    // Confirms getEntries() resolves the bundle's own config/whatsnew.json (two levels up from
    // src/Management) and matches exactly what WhatsNewJsonReader::read() would return for it
    public function testGetEntriesReadsTheBundlesOwnWhatsNewJsonFile(): void
    {
        $expected = WhatsNewJsonReader::read(\dirname(__DIR__, 2) . '/config/whatsnew.json');

        $entries = (new WhatsNewProvider())->getEntries();

        $this->assertNotEmpty($entries);
        $this->assertEquals($expected, $entries);
    }
}
