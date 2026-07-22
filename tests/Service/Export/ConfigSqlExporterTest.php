<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Service\Export;

use c975L\ConfigBundle\Service\Export\ConfigSqlExporter;
use c975L\ConfigBundle\Service\Export\Encoder\SqlEncoder;
use c975L\ConfigBundle\Service\Export\TableExporter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class ConfigSqlExporterTest extends TestCase
{
    private function createExporter(Connection $connection, ?Security $security = null): ConfigSqlExporter
    {
        if (null === $security) {
            $security = $this->createStub(Security::class);
            $security->method('isGranted')->willReturn(true);
        }

        return new ConfigSqlExporter($connection, new TableExporter(new SqlEncoder($connection)), $security);
    }

    private function createConnection(array $rows): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);
        $connection->method('quote')->willReturnCallback(static fn (string $value) => "'" . addslashes($value) . "'");

        return $connection;
    }

    public function testExportQueriesEverySiteConfigColumnOrderedBySlugForSuperAdmin(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                'SELECT `label`, `slug`, `is_sensitive`, `is_restricted`, `value`, `kind`, `group`, `description`, `severity`, `creation`, `modification` '
                . 'FROM `site_config` ORDER BY `slug`'
            )
            ->willReturn([]);

        $this->createExporter($connection)->export();
    }

    public function testExportExcludesRestrictedConfigsForNonSuperAdmin(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('WHERE `is_restricted` IS NULL OR `is_restricted` = 0'))
            ->willReturn([]);

        $this->createExporter($connection, $security)->export();
    }

    public function testExportUpsertsNonSensitiveRows(): void
    {
        $connection = $this->createConnection([
            ['slug' => 'site-name', 'is_sensitive' => false, 'value' => 'My Site'],
        ]);

        $response = $this->createExporter($connection)->export();

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $response->getContent());
    }

    public function testExportInsertIgnoresSensitiveRows(): void
    {
        $connection = $this->createConnection([
            ['slug' => 'stripe-secret-key', 'is_sensitive' => true, 'value' => 'sk_live_xxx'],
        ]);

        $response = $this->createExporter($connection)->export();

        $this->assertStringContainsString('INSERT IGNORE INTO `site_config`', $response->getContent());
        $this->assertStringNotContainsString('ON DUPLICATE KEY UPDATE', $response->getContent());
    }

    public function testExportNeverRewritesCreationOnUpdate(): void
    {
        $connection = $this->createConnection([
            ['slug' => 'site-name', 'is_sensitive' => false, 'value' => 'My Site', 'creation' => '2026-01-01'],
        ]);

        $response = $this->createExporter($connection)->export();

        $this->assertStringNotContainsString('`creation`=VALUES(`creation`)', $response->getContent());
        $this->assertStringContainsString('`value`=VALUES(`value`)', $response->getContent());
    }

    public function testExportProducesDownloadableSqlResponse(): void
    {
        $response = $this->createExporter($this->createConnection([]))->export();

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment; filename="site_config_', $response->headers->get('Content-Disposition'));
    }
}
