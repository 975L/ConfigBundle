<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ProcedureProvider;
use PHPUnit\Framework\TestCase;

class ProcedureProviderTest extends TestCase
{
    // Reads the bundle's own config/procedures.json and turns each row into a slug + title + body entry
    public function testGetProceduresReturnsOneEntryPerJsonRow(): void
    {
        $rawEntries = json_decode(file_get_contents(\dirname(__DIR__, 2) . '/config/procedures.json'), true);

        $entries = (new ProcedureProvider())->getProcedures();

        $this->assertCount(\count($rawEntries), $entries);
        $this->assertSame($rawEntries[0]['slug'], $entries[0]['slug']);
        $this->assertNotSame('', $entries[0]['title']);
        $this->assertNotSame('', $entries[0]['body']);
    }
}
