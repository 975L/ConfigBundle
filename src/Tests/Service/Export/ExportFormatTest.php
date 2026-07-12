<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Service\Export;

use c975L\ConfigBundle\Service\Export\ExportFormat;
use PHPUnit\Framework\TestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class ExportFormatTest extends TestCase
{
    // Locks down the backing string values, since TableExporter/SqlEncoder match against them
    // (ExportFormat::Sql->value, ContentType map keys...) and a silent rename would break both
    public function testCasesExposeTheExpectedBackingValues(): void
    {
        $this->assertSame('sql', ExportFormat::Sql->value);
        $this->assertSame('csv', ExportFormat::Csv->value);
        $this->assertSame('json', ExportFormat::Json->value);
    }

    public function testFromResolvesACaseFromItsBackingValue(): void
    {
        $this->assertSame(ExportFormat::Sql, ExportFormat::from('sql'));
        $this->assertSame(ExportFormat::Csv, ExportFormat::from('csv'));
        $this->assertSame(ExportFormat::Json, ExportFormat::from('json'));
    }

    public function testFromThrowsForAnUnknownValue(): void
    {
        $this->expectException(\ValueError::class);

        ExportFormat::from('xml');
    }
}
