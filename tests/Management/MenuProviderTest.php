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
use PHPUnit\Framework\TestCase;

class MenuProviderTest extends TestCase
{
    public function testGetMenuSectionReturnsTheManagementSectionInTheSiteDomain(): void
    {
        $provider = new MenuProvider();

        $this->assertInstanceOf(MenuProviderInterface::class, $provider);
        $this->assertSame(['label' => 'label.management', 'translation_domain' => 'site'], $provider->getMenuSection());
    }

    public function testGetMenusExposesTheConfigCrudControllerEntry(): void
    {
        $provider = new MenuProvider();

        $menus = $provider->getMenus();

        $this->assertSame(ConfigCrudController::class, $menus['config']['controller']);
        $this->assertSame('label.config', $menus['config']['label']);
        $this->assertSame('config', $menus['config']['translation_domain']);
    }

    // Theme configs are edited via Config's own "theme" group (its picker screen) since ThemeCrudController was removed - no separate menu entry
    public function testGetMenusDoesNotExposeASeparateThemeEntry(): void
    {
        $provider = new MenuProvider();

        $menus = $provider->getMenus();

        $this->assertArrayNotHasKey('theme', $menus);
    }

    public function testGetLinksExposesTheWhatsNewLink(): void
    {
        $provider = new MenuProvider();

        $links = $provider->getLinks();

        $this->assertSame('management_whatsnew_index', $links['whatsnew']['name']);
        $this->assertSame('label.whatsnew', $links['whatsnew']['label']);
        $this->assertSame('config', $links['whatsnew']['translation_domain']);
    }

    // Restricted to ROLE_SUPER_ADMIN since it writes arbitrary content straight into the database (see ContentImportController)
    public function testGetLinksExposesTheContentImportLinkRestrictedToSuperAdmin(): void
    {
        $provider = new MenuProvider();

        $links = $provider->getLinks();

        $this->assertSame('management_content_import_index', $links['content_import']['name']);
        $this->assertSame('label.content_import', $links['content_import']['label']);
        $this->assertSame('config', $links['content_import']['translation_domain']);
        $this->assertSame('ROLE_SUPER_ADMIN', $links['content_import']['role']);
    }
}
