<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Controller\Management\ConfigShortcutController;
use c975L\ConfigBundle\Controller\Management\MaintenanceShortcutController;
use c975L\ConfigBundle\Management\ConfigShortcutProvider;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigShortcutProviderTest extends TestCase
{
    // Builds a ConfigServiceInterface double returning the given slug => value map
    private function createConfigService(array $values): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturnCallback(static fn (string $slug) => $values[$slug] ?? null);

        return $service;
    }

    // Translator double that returns the translation key untouched, so labels stay assertable
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return $translator;
    }

    public function testGetShortcutsReflectsMaintenanceDisabledState(): void
    {
        $configService = $this->createConfigService([
            'site-maintenance' => false,
            'site-role-admin' => 'ROLE_ADMIN',
        ]);
        $provider = new ConfigShortcutProvider($this->createTranslator(), $configService);

        $shortcuts = $provider->getShortcuts();

        $this->assertSame(ConfigShortcutController::CLEAR_CACHE_ROUTE, $shortcuts[0]['route']);
        $this->assertFalse($shortcuts[0]['active']);
        $this->assertSame(MaintenanceShortcutController::TOGGLE_ROUTE_MAINTENANCE, $shortcuts[1]['route']);
        $this->assertFalse($shortcuts[1]['active']);
        $this->assertSame('label.maintenance_enable', $shortcuts[1]['label']);
        $this->assertSame('ROLE_ADMIN', $shortcuts[1]['role']);
    }

    public function testGetShortcutsReflectsMaintenanceEnabledState(): void
    {
        $configService = $this->createConfigService([
            'site-maintenance' => true,
            'site-role-admin' => 'ROLE_ADMIN',
        ]);
        $provider = new ConfigShortcutProvider($this->createTranslator(), $configService);

        $shortcuts = $provider->getShortcuts();

        $this->assertTrue($shortcuts[1]['active']);
        $this->assertSame('label.maintenance_disable', $shortcuts[1]['label']);
    }
}
