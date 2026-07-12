<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Service\Export;

use c975L\ConfigBundle\Service\Export\Encoder\SqlEncoder;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class TableExporterTest extends TestCase
{
    private function createExporter(): TableExporter
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $value) => "'" . addslashes($value) . "'");

        return new TableExporter(new SqlEncoder($connection));
    }

    public function testExportJsonProducesAttachmentResponseWithEncodedRows(): void
    {
        $response = $this->createExporter()->export(ExportFormat::Json, 'site_config', [['slug' => 'site-name', 'value' => 'My Site']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment; filename="site_config_', $response->headers->get('Content-Disposition'));
        $this->assertSame([['slug' => 'site-name', 'value' => 'My Site']], json_decode($response->getContent(), true));
    }

    public function testExportCsvUsesCsvContentTypeAndFilenameExtension(): void
    {
        $response = $this->createExporter()->export(ExportFormat::Csv, 'site_config', [['slug' => 'site-name']]);

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.csv"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('slug', $response->getContent());
    }

    public function testExportSqlDelegatesToSqlEncoderWithTableNameInContext(): void
    {
        $response = $this->createExporter()->export(ExportFormat::Sql, 'site_config', [['slug' => 'site-name']]);

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('INSERT INTO `site_config`', $response->getContent());
    }
}
