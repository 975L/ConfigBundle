<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\ConfigImportProvider;
use c975L\ConfigBundle\Repository\ConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ConfigImportProviderTest extends TestCase
{
    private function createConfigRepository(?Config $existingConfig = null): ConfigRepository
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findOneBy')->willReturn($existingConfig);

        return $repository;
    }

    public function testSupportsImportOnlyMatchesSiteConfigKind(): void
    {
        $provider = new ConfigImportProvider($this->createStub(EntityManagerInterface::class), $this->createConfigRepository());

        $this->assertTrue($provider->supportsImport('site_config'));
        $this->assertFalse($provider->supportsImport('site_page'));
    }

    public function testImportCreatesANewNonSensitiveConfig(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new ConfigImportProvider($em, $this->createConfigRepository());

        $result = $provider->import([[
            'slug' => 'site-title',
            'label' => 'Site title',
            'isSensitive' => false,
            'isRestricted' => false,
            'value' => 'My Site',
            'kind' => Config::TYPE_TEXT,
            'group' => Config::GROUP_GENERAL,
            'description' => null,
            'severity' => null,
        ]]);

        $this->assertSame(['created' => 1, 'updated' => 0], $result);
        $this->assertCount(1, $persisted);
        $this->assertSame('My Site', $persisted[0]->getValue());
    }

    public function testImportOverwritesAnExistingNonSensitiveConfig(): void
    {
        $existing = (new Config())->setSlug('site-title')->setLabel('Old label')->setKind(Config::TYPE_TEXT)->setValue('Old value');

        $em = $this->createStub(EntityManagerInterface::class);

        $provider = new ConfigImportProvider($em, $this->createConfigRepository($existing));

        $result = $provider->import([[
            'slug' => 'site-title',
            'label' => 'New label',
            'isSensitive' => false,
            'value' => 'New value',
            'kind' => Config::TYPE_TEXT,
        ]]);

        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertSame('New label', $existing->getLabel());
        $this->assertSame('New value', $existing->getValue());
    }

    public function testImportSkipsAnExistingSensitiveConfigToPreserveItsProductionValue(): void
    {
        $existing = (new Config())->setSlug('api-key')->setLabel('API key')->setKind(Config::TYPE_TEXT)->setValue('prod-secret');

        $em = $this->createStub(EntityManagerInterface::class);

        $provider = new ConfigImportProvider($em, $this->createConfigRepository($existing));

        $result = $provider->import([[
            'slug' => 'api-key',
            'label' => 'API key',
            'isSensitive' => true,
            'value' => 'dev-secret',
            'kind' => Config::TYPE_TEXT,
        ]]);

        $this->assertSame(['created' => 0, 'updated' => 0], $result);
        $this->assertSame('prod-secret', $existing->getValue());
    }

    public function testImportCreatesAMissingSensitiveConfigWithItsExportedValue(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new ConfigImportProvider($em, $this->createConfigRepository());

        $result = $provider->import([[
            'slug' => 'api-key',
            'label' => 'API key',
            'isSensitive' => true,
            'value' => 'encrypted-value',
            'kind' => Config::TYPE_TEXT,
        ]]);

        $this->assertSame(['created' => 1, 'updated' => 0], $result);
        $this->assertSame('encrypted-value', $persisted[0]->getValue());
    }
}
