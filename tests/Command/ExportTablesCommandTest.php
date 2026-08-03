<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\ExportTablesCommand;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ExportTablesCommandTest extends TestCase
{
    private function createParameterBag(): ParameterBagInterface
    {
        $bag = $this->createStub(ParameterBagInterface::class);
        $bag->method('get')->willReturnCallback(
            fn (string $name): string => 'kernel.project_dir' === $name ? sys_get_temp_dir() : ''
        );

        return $bag;
    }

    // Bogus credentials make mysql fail instantly, driving getTableList() into its error branch
    private function createConfigService(): ConfigServiceInterface
    {
        $values = [
            'site-backup-database' => 'test_db',
            'site-backup-db-host' => '127.0.0.1',
            'site-backup-db-user' => 'c975l_test_invalid_user',
            'site-backup-db-password' => 'wrong',
        ];
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturnCallback(fn (string $key) => $values[$key] ?? '');

        return $service;
    }

    public function testConfigureHasPrefixAndOutputOptionsWithDefaultPrefix(): void
    {
        $command = new ExportTablesCommand($this->createParameterBag(), $this->createConfigService());

        $this->assertTrue($command->getDefinition()->hasOption('prefix'));
        $this->assertSame('site_', $command->getDefinition()->getOption('prefix')->getDefault());
        $this->assertTrue($command->getDefinition()->hasOption('output'));
    }

    public function testExportTablesReturnsErrorWhenDatabaseNotConfigured(): void
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('');

        $command = new ExportTablesCommand($this->createParameterBag(), $configService);

        $result = $command->exportTables();

        $this->assertSame('site-backup-database is not configured in ConfigBundle.', $result['error']);
        $this->assertSame([], $result['tables']);
    }

    // Neither value can be bound as a parameter in the "mysql --execute" query listing the tables, so both are refused before any process is started
    public function testExportTablesRefusesADatabaseNameThatIsNotAPlainIdentifier(): void
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            fn (string $key) => 'site-backup-database' === $key ? "test_db' OR '1'='1" : ''
        );

        $command = new ExportTablesCommand($this->createParameterBag(), $configService);

        $result = $command->exportTables();

        $this->assertSame('site-backup-database must only contain letters, digits and underscores.', $result['error']);
    }

    // A "%" or "_" in the prefix is a silent LIKE wildcard, and every table this command lists is TRUNCATEd on replay
    public function testExportTablesRefusesAPrefixHoldingALikeWildcard(): void
    {
        $command = new ExportTablesCommand($this->createParameterBag(), $this->createConfigService());

        $result = $command->exportTables('si%');

        $this->assertSame('The table prefix must only contain letters, digits and underscores.', $result['error']);
    }

    public function testExportTablesReturnsErrorWhenMysqlFailsToListTables(): void
    {
        $command = new ExportTablesCommand($this->createParameterBag(), $this->createConfigService());

        $result = $command->exportTables();

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('mysql failed while listing tables', $result['error']);
        $this->assertSame([], $result['tables']);
    }
}
