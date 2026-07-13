<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Service\Export\Encoder;

use c975L\ConfigBundle\Service\Export\Encoder\SqlEncoder;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class SqlEncoderTest extends TestCase
{
    // Builds a Connection double whose quote() mimics a simple SQL string-literal quoting
    private function createConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $value) => "'" . addslashes($value) . "'");

        return $connection;
    }

    public function testSupportsEncodingOnlyRecognizesSqlFormat(): void
    {
        $encoder = new SqlEncoder($this->createConnection());

        $this->assertTrue($encoder->supportsEncoding(ExportFormat::Sql->value));
        $this->assertFalse($encoder->supportsEncoding(ExportFormat::Csv->value));
    }

    public function testEncodeThrowsWhenTableContextOptionIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SqlEncoder($this->createConnection()))->encode([], ExportFormat::Sql->value, []);
    }

    public function testEncodeGeneratesPlainInsertWithoutPrimaryKey(): void
    {
        $encoder = new SqlEncoder($this->createConnection());

        $sql = $encoder->encode(
            [['slug' => 'site-name', 'value' => 'My Site']],
            ExportFormat::Sql->value,
            ['table' => 'site_config'],
        );

        $this->assertStringContainsString(
            "INSERT INTO `site_config` (`slug`, `value`) VALUES ('site-name', 'My Site');",
            $sql,
        );
    }

    public function testEncodeGeneratesUpsertWithPrimaryKeyExcludingGivenColumnsFromUpdate(): void
    {
        $encoder = new SqlEncoder($this->createConnection());

        $sql = $encoder->encode(
            [['id' => '1', 'slug' => 'site-name', 'creation' => '2026-01-01']],
            ExportFormat::Sql->value,
            ['table' => 'site_config', 'primary_key' => 'id', 'exclude_from_update' => ['creation']],
        );

        $this->assertStringContainsString(
            "INSERT INTO `site_config` (`id`, `slug`, `creation`) VALUES ('1', 'site-name', '2026-01-01') ON DUPLICATE KEY UPDATE `slug`=VALUES(`slug`);",
            $sql,
        );
    }

    public function testEncodeGeneratesInsertIgnoreWhenCallbackMatches(): void
    {
        $encoder = new SqlEncoder($this->createConnection());

        $sql = $encoder->encode(
            [['id' => '1', 'slug' => 'site-name']],
            ExportFormat::Sql->value,
            [
                'table' => 'site_config',
                'primary_key' => 'id',
                'insert_ignore_when' => static fn (array $row) => 'site-name' === $row['slug'],
            ],
        );

        $this->assertStringContainsString(
            "INSERT IGNORE INTO `site_config` (`id`, `slug`) VALUES ('1', 'site-name');",
            $sql,
        );
    }

    public function testEncodeRendersNullValuesAsSqlNull(): void
    {
        $encoder = new SqlEncoder($this->createConnection());

        $sql = $encoder->encode(
            [['slug' => 'site-name', 'value' => null]],
            ExportFormat::Sql->value,
            ['table' => 'site_config'],
        );

        $this->assertStringContainsString("VALUES ('site-name', NULL);", $sql);
    }

    public function testEncodeIncludesTableNameHeaderAndNamesStatement(): void
    {
        $encoder = new SqlEncoder($this->createConnection());

        $sql = $encoder->encode([], ExportFormat::Sql->value, ['table' => 'site_config']);

        $this->assertStringContainsString('-- site_config export --', $sql);
        $this->assertStringContainsString('SET NAMES utf8mb4;', $sql);
    }
}
