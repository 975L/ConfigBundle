<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Controller\Management\ConfigPruneController;
use c975L\ConfigBundle\Controller\Management\ConfigShortcutController;
use c975L\ConfigBundle\Controller\Management\MaintenanceShortcutController;
use c975L\ConfigBundle\Management\ConfigShortcutProvider;
use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\UiBundle\Entity\Form;
use c975L\UiBundle\Repository\FormRepository;
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

    // The "register" Form's own $enabled flag drives the registration toggle - a Form not seeded yet counts as disabled
    private function createFormRepository(?bool $registerEnabled = false): FormRepository
    {
        $repository = $this->createStub(FormRepository::class);
        $repository->method('findOneBy')->willReturn(
            null === $registerEnabled ? null : (new Form())->setName('register')->setEnabled($registerEnabled)
        );

        return $repository;
    }

    public function testGetShortcutsReflectsMaintenanceDisabledState(): void
    {
        $configService = $this->createConfigService([
            'site-maintenance' => false,
            'site-role-admin' => 'ROLE_ADMIN',
        ]);
        $provider = new ConfigShortcutProvider($this->createTranslator(), $configService, $this->createFormRepository());

        $shortcuts = $provider->getShortcuts();

        $this->assertSame(ConfigShortcutController::CLEAR_CACHE_ROUTE, $shortcuts[0]['route']);
        $this->assertFalse($shortcuts[0]['active']);
        $this->assertSame(ShortcutProviderInterface::CATEGORY_MAINTENANCE, $shortcuts[0]['category']);
        $this->assertSame(ConfigShortcutController::EXPORT_SQL_ROUTE, $shortcuts[1]['route']);
        $this->assertFalse($shortcuts[1]['active']);
        $this->assertSame('ROLE_ADMIN', $shortcuts[1]['role']);
        $this->assertSame(ShortcutProviderInterface::CATEGORY_EXPORT, $shortcuts[1]['category']);
        $this->assertSame(ConfigShortcutController::EXPORT_SYNC_ALL_ROUTE, $shortcuts[2]['route']);
        $this->assertFalse($shortcuts[2]['active']);
        $this->assertSame('ROLE_ADMIN', $shortcuts[2]['role']);
        $this->assertSame(ShortcutProviderInterface::CATEGORY_EXPORT, $shortcuts[2]['category']);
        $this->assertSame(ConfigPruneController::INDEX_ROUTE, $shortcuts[3]['route']);
        $this->assertFalse($shortcuts[3]['active']);
        $this->assertSame('ROLE_SUPER_ADMIN', $shortcuts[3]['role']);
        $this->assertSame(ShortcutProviderInterface::CATEGORY_MAINTENANCE, $shortcuts[3]['category']);
        // The only tile opening a page instead of acting, hence a link with no CSRF token (see _shortcuts.html.twig)
        $this->assertSame('GET', $shortcuts[3]['method']);
        $this->assertSame(ConfigShortcutController::SITEMAPS_CREATE_ROUTE, $shortcuts[4]['route']);
        $this->assertFalse($shortcuts[4]['active']);
        $this->assertSame('ROLE_SUPER_ADMIN', $shortcuts[4]['role']);
        $this->assertSame(ShortcutProviderInterface::CATEGORY_SITE, $shortcuts[4]['category']);
        // Moved here from SiteBundle alongside the command it runs: a site without a site foundation exports its tables the same way
        $this->assertSame(ConfigShortcutController::EXPORT_TABLES_ROUTE, $shortcuts[5]['route']);
        $this->assertFalse($shortcuts[5]['active']);
        $this->assertSame('ROLE_SUPER_ADMIN', $shortcuts[5]['role']);
        $this->assertSame(ShortcutProviderInterface::CATEGORY_EXPORT, $shortcuts[5]['category']);
        // Moved here alongside the "register" Form it flips, which this bundle now seeds
        $this->assertSame(ConfigShortcutController::REGISTRATION_ENABLED_TOGGLE_ROUTE, $shortcuts[6]['route']);
        $this->assertSame('label.user_registration_enable', $shortcuts[6]['label']);
        $this->assertFalse($shortcuts[6]['active']);
        $this->assertSame(ShortcutProviderInterface::CATEGORY_SITE, $shortcuts[6]['category']);
        $this->assertSame(MaintenanceShortcutController::TOGGLE_ROUTE_MAINTENANCE, $shortcuts[7]['route']);
        $this->assertFalse($shortcuts[7]['active']);
        $this->assertSame('label.maintenance_enable', $shortcuts[7]['label']);
        $this->assertSame('ROLE_ADMIN', $shortcuts[7]['role']);
        $this->assertSame(ShortcutProviderInterface::CATEGORY_MAINTENANCE, $shortcuts[7]['category']);
    }

    // When registration is already enabled, the tile offers to disable it and is marked active
    public function testGetShortcutsOffersToDisableRegistrationWhenEnabled(): void
    {
        $provider = new ConfigShortcutProvider($this->createTranslator(), $this->createConfigService([]), $this->createFormRepository(true));

        $this->assertSame('label.user_registration_disable', $provider->getShortcuts()[6]['label']);
        $this->assertTrue($provider->getShortcuts()[6]['active']);
    }

    // No "register" Form seeded yet counts as disabled, same as an explicit false
    public function testGetShortcutsTreatsAMissingRegisterFormAsDisabled(): void
    {
        $provider = new ConfigShortcutProvider($this->createTranslator(), $this->createConfigService([]), $this->createFormRepository(null));

        $this->assertFalse($provider->getShortcuts()[6]['active']);
    }

    // Every other shortcut stays a POST form, the template defaulting to it when 'method' is absent
    public function testEveryOtherShortcutOmitsTheMethodKey(): void
    {
        $provider = new ConfigShortcutProvider($this->createTranslator(), $this->createConfigService([]), $this->createFormRepository());

        foreach ($provider->getShortcuts() as $shortcut) {
            if (ConfigPruneController::INDEX_ROUTE !== $shortcut['route']) {
                $this->assertArrayNotHasKey('method', $shortcut);
            }
        }
    }

    public function testGetShortcutsReflectsMaintenanceEnabledState(): void
    {
        $configService = $this->createConfigService([
            'site-maintenance' => true,
            'site-role-admin' => 'ROLE_ADMIN',
        ]);
        $provider = new ConfigShortcutProvider($this->createTranslator(), $configService, $this->createFormRepository());

        $shortcuts = $provider->getShortcuts();

        $this->assertTrue($shortcuts[7]['active']);
        $this->assertSame('label.maintenance_disable', $shortcuts[7]['label']);
    }
}
