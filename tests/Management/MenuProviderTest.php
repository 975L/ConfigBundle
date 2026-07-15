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
use c975L\ConfigBundle\Controller\Management\ThemeCrudController;
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

    public function testGetMenusExposesTheThemeCrudControllerEntry(): void
    {
        $provider = new MenuProvider();

        $menus = $provider->getMenus();

        $this->assertSame(ThemeCrudController::class, $menus['theme']['controller']);
        $this->assertSame('label.theme', $menus['theme']['label']);
        $this->assertSame('config', $menus['theme']['translation_domain']);
    }

    public function testGetLinksExposesTheWhatsNewLink(): void
    {
        $provider = new MenuProvider();

        $links = $provider->getLinks();

        $this->assertSame('management_whatsnew_index', $links['whatsnew']['name']);
        $this->assertSame('label.whatsnew', $links['whatsnew']['label']);
        $this->assertSame('config', $links['whatsnew']['translation_domain']);
    }
}
