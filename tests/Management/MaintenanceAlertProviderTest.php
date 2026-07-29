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
use c975L\ConfigBundle\Management\MaintenanceAlertProvider;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class MaintenanceAlertProviderTest extends TestCase
{
    // Builds the 'site-maintenance' entry as the toggle leaves it: its value, and the date it was last flipped (no DB needed)
    private function createConfig(bool $enabled, string $modification): Config
    {
        $config = new Config();
        $config->setSlug('site-maintenance');
        $config->setLabel('label.site_maintenance');
        $config->setKind(Config::TYPE_BOOL);
        $config->setValue($enabled);
        $config->setCreation(new \DateTime('-1 year'));
        $config->setModification(new \DateTime($modification));

        $reflection = new \ReflectionProperty(Config::class, 'id');
        $reflection->setValue($config, 7);

        return $config;
    }

    private function createProvider(?Config $config, array $values = []): MaintenanceAlertProvider
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findOneBy')->willReturn($config);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('getBool')->willReturnCallback(
            static fn ($value) => \in_array($value, [true, 'true', '1', 1], true)
        );
        $configService->method('get')->willReturnCallback(static fn (string $slug) => $values[$slug] ?? null);

        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('setEntityId')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/config/7/edit');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []) => strtr($id, $parameters)
        );

        return new MaintenanceAlertProvider($repository, $configService, $adminUrlGenerator, $translator);
    }

    public function testNoAlertWhenTheSiteIsOpenToItsVisitors(): void
    {
        $this->assertSame([], $this->createProvider($this->createConfig(false, '-1 hour'))->getAlerts());
    }

    // A site whose config entry was never loaded must not raise an alert either
    public function testNoAlertWhenTheMaintenanceConfigIsMissing(): void
    {
        $this->assertSame([], $this->createProvider(null)->getAlerts());
    }

    public function testAFreshMaintenanceIsOnlyReportedAsAState(): void
    {
        $alerts = $this->createProvider($this->createConfig(true, '2026-07-29 09:30:00'))->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame(Config::SEVERITY_INFO, $alerts[0]['severity']);
        $this->assertSame('label.maintenance_alert', $alerts[0]['label']);
        $this->assertSame('description.maintenance_alert', $alerts[0]['description']);
        $this->assertSame('/management/config/7/edit', $alerts[0]['url']);
    }

    // Past a couple of days, search engines stop reading the 503 as temporary, so the alert has to become actionable
    public function testAMaintenanceLastingDaysBecomesADangerAlert(): void
    {
        $alerts = $this->createProvider($this->createConfig(true, '-3 days'))->getAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame(Config::SEVERITY_DANGER, $alerts[0]['severity']);
        $this->assertSame('description.maintenance_alert_long', $alerts[0]['description']);
    }

    // The url to hand over to whoever has to see the closed site without being given an account
    public function testASecondAlertCarriesThePreviewUrlBuiltWithTheToken(): void
    {
        $alerts = $this->createProvider(
            $this->createConfig(true, '-1 hour'),
            ['site-url' => 'https://example.com/', 'site-maintenance-hash' => 'abc123'],
        )->getAlerts();

        $this->assertCount(2, $alerts);
        $this->assertSame('label.maintenance_preview_link', $alerts[1]['label']);
        $this->assertSame('https://example.com/?t=abc123', $alerts[1]['url']);
        $this->assertSame(Config::SEVERITY_INFO, $alerts[1]['severity']);
    }

    // Nothing to hand out on a site closed by editing the entry by hand, the toggle being what fills the token
    public function testNoPreviewUrlWhenTheTokenOrTheSiteUrlIsMissing(): void
    {
        $config = $this->createConfig(true, '-1 hour');

        $this->assertCount(1, $this->createProvider($config, ['site-url' => 'https://example.com'])->getAlerts());
        $this->assertCount(1, $this->createProvider($config, ['site-maintenance-hash' => 'abc123'])->getAlerts());
    }

    public function testTheAlertCarriesTheDateTheMaintenanceStarted(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []) => $parameters['%date%'] ?? ''
        );

        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findOneBy')->willReturn($this->createConfig(true, '2026-07-29 09:30:00'));

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('getBool')->willReturn(true);

        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('setEntityId')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/config/7/edit');

        $provider = new MaintenanceAlertProvider($repository, $configService, $adminUrlGenerator, $translator);

        $this->assertSame('29/07/2026 09:30', $provider->getAlerts()[0]['description']);
    }
}
