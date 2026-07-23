<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ConfigExportProvider;
use c975L\ConfigBundle\Management\ConfigImportProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class ConfigExportProviderTest extends TestCase
{
    private function createProvider(Connection $connection, ?Security $security = null): ConfigExportProvider
    {
        if (null === $security) {
            $security = $this->createStub(Security::class);
            $security->method('isGranted')->willReturn(true);
        }

        return new ConfigExportProvider($connection, $security);
    }

    private function createConnection(array $rows): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return $connection;
    }

    public function testGetKindMatchesConfigImportProvider(): void
    {
        $provider = $this->createProvider($this->createConnection([]));

        $this->assertSame(ConfigImportProvider::KIND, $provider->getKind());
    }

    public function testExportAllMapsRowsToTheImportEnvelopeShape(): void
    {
        $connection = $this->createConnection([[
            'slug' => 'site-name',
            'label' => 'Site name',
            'is_sensitive' => false,
            'is_restricted' => null,
            'value' => 'My Site',
            'kind' => 'text',
            'group' => 'general',
            'description' => 'description.site_name',
            'severity' => 0,
        ]]);

        $data = $this->createProvider($connection)->exportAll();

        $this->assertSame([], $data['files']);
        $this->assertSame([[
            'slug' => 'site-name',
            'label' => 'Site name',
            'isSensitive' => false,
            'isRestricted' => false,
            'value' => 'My Site',
            'kind' => 'text',
            'group' => 'general',
            'description' => 'description.site_name',
            'severity' => 0,
        ]], $data['items']);
    }

    public function testFetchRowsExcludesRestrictedConfigsForNonSuperAdmin(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('WHERE `is_restricted` IS NULL OR `is_restricted` = 0'))
            ->willReturn([]);

        $this->createProvider($connection, $security)->fetchRows();
    }

    public function testFetchRowsIncludesRestrictedConfigsForSuperAdmin(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalNot($this->stringContains('WHERE')))
            ->willReturn([]);

        $this->createProvider($connection)->fetchRows();
    }
}
