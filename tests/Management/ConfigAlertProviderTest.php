<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Controller\Management\ConfigCrudController;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\ConfigAlertProvider;
use c975L\ConfigBundle\Repository\ConfigRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;

class ConfigAlertProviderTest extends TestCase
{
    // Builds a Config entity requiring attention (slug/description/severity, no DB needed)
    private function createConfig(int $id, string $slug, string $severity): Config
    {
        $config = new Config();
        $config->setSlug($slug);
        $config->setLabel($slug);
        $config->setSeverity($severity);
        $config->setDescription('description-' . $slug);
        $config->setCreation(new \DateTime());
        $config->setModification(new \DateTime());

        $reflection = new \ReflectionProperty(Config::class, 'id');
        $reflection->setValue($config, $id);

        return $config;
    }

    public function testGetAlertsMapsEachConfigRequiringAttentionToAnAlertWithEditUrl(): void
    {
        $config = $this->createConfig(42, 'site-maintenance-hash', Config::SEVERITY_WARNING);

        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findRequiringAttention')->willReturn([$config]);

        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setController')->with(ConfigCrudController::class)->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setAction')->with(Action::EDIT)->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setEntityId')->with(42)->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/config/42/edit');

        $provider = new ConfigAlertProvider($repository, $adminUrlGenerator);

        $alerts = $provider->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('label.site_maintenance_hash', $alerts[0]['label']);
        $this->assertSame('description-site-maintenance-hash', $alerts[0]['description']);
        $this->assertSame(Config::SEVERITY_WARNING, $alerts[0]['severity']);
        $this->assertSame('/management/config/42/edit', $alerts[0]['url']);
    }

    public function testGetAlertsReturnsEmptyArrayWhenNoConfigRequiresAttention(): void
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findRequiringAttention')->willReturn([]);

        $provider = new ConfigAlertProvider($repository, $this->createStub(AdminUrlGeneratorInterface::class));

        $this->assertSame([], $provider->getAlerts());
    }
}
