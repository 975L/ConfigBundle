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
use c975L\ConfigBundle\Management\MenuProvider;
use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;

class MenuProviderTest extends TestCase
{
    // Config service double, returning the given map of config values by key
    private function createConfigService(array $values = []): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturnCallback(static fn (string $key) => $values[$key] ?? null);

        return $service;
    }

    public function testGetMenuSectionReturnsTheManagementSectionInTheSiteDomain(): void
    {
        $provider = new MenuProvider($this->createConfigService());

        $this->assertInstanceOf(MenuProviderInterface::class, $provider);
        $this->assertSame(['label' => 'label.management', 'translation_domain' => 'site'], $provider->getMenuSection());
    }

    public function testGetMenusExposesTheConfigCrudControllerEntry(): void
    {
        $provider = new MenuProvider($this->createConfigService());

        $menus = $provider->getMenus();

        $this->assertSame(ConfigCrudController::class, $menus['config']['controller']);
        $this->assertSame('label.config', $menus['config']['label']);
        $this->assertSame('config', $menus['config']['translation_domain']);
    }

    // Theme configs are edited via Config's own "theme" group (its picker screen) since ThemeCrudController was removed - no separate menu entry
    public function testGetMenusDoesNotExposeASeparateThemeEntry(): void
    {
        $provider = new MenuProvider($this->createConfigService());

        $menus = $provider->getMenus();

        $this->assertArrayNotHasKey('theme', $menus);
    }

    public function testGetLinksExposesTheWhatsNewLink(): void
    {
        $provider = new MenuProvider($this->createConfigService());

        $links = $provider->getLinks();

        $this->assertSame('management_whatsnew_index', $links['whatsnew']['name']);
        $this->assertSame('label.whatsnew', $links['whatsnew']['label']);
        $this->assertSame('config', $links['whatsnew']['translation_domain']);
    }

    // Restricted to ROLE_SUPER_ADMIN since it writes arbitrary content straight into the database (see ContentImportController)
    public function testGetLinksExposesTheContentImportLinkRestrictedToSuperAdmin(): void
    {
        $provider = new MenuProvider($this->createConfigService());

        $links = $provider->getLinks();

        $this->assertSame('management_content_import_index', $links['content_import']['name']);
        $this->assertSame('label.content_import', $links['content_import']['label']);
        $this->assertSame('config', $links['content_import']['translation_domain']);
        $this->assertSame('ROLE_SUPER_ADMIN', $links['content_import']['role']);
        $this->assertSame('label.content_import_help', $links['content_import']['description']);
    }

    // The site link uses the site's own "name"/"url" configs (name passed as a translation parameter so the "Site :" prefix stays translated), opens in a new tab, and is pinned to always stay at the very bottom of the links section (see MenuBuilder::sortAlphabetically)
    public function testGetLinksExposesThePinnedSiteLinkUsingItsNameAndUrlConfigs(): void
    {
        $provider = new MenuProvider($this->createConfigService(['site-name' => 'My Site', 'site-url' => 'https://example.test/']));

        $links = $provider->getLinks();

        $this->assertSame('https://example.test/', $links['site']['url']);
        $this->assertSame('label.site_link', $links['site']['label']);
        $this->assertSame(['%name%' => 'My Site'], $links['site']['label_parameters']);
        $this->assertSame('_blank', $links['site']['target']);
        $this->assertTrue($links['site']['pinned']);
        $this->assertSame('description.site_link', $links['site']['description']);
    }

    // No usable label/url to build a link from until the site's url config is actually filled in - onboarding not done yet
    public function testGetLinksOmitsTheSiteLinkWhenSiteUrlIsNotConfigured(): void
    {
        $provider = new MenuProvider($this->createConfigService());

        $links = $provider->getLinks();

        $this->assertArrayNotHasKey('site', $links);
    }
}
