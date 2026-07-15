<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\DashboardController;
use c975L\ConfigBundle\Management\AlertBuilder;
use c975L\ConfigBundle\Management\MenuBuilder;
use c975L\ConfigBundle\Management\ShortcutBuilder;
use c975L\ConfigBundle\Management\WhatsNewBuilder;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\UiBundle\Registry\FormThemeRegistry;
use c975L\UiBundle\Registry\ScriptAdminRegistry;
use c975L\UiBundle\Registry\StylesheetManagementRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class DashboardControllerTest extends TestCase
{
    private function createController(bool $debug, array $managementStylesheets): DashboardController
    {
        $stylesheetManagementRegistry = $this->createStub(StylesheetManagementRegistry::class);
        $stylesheetManagementRegistry->method('all')->willReturn($managementStylesheets);

        return new DashboardController(
            $this->createStub(MenuBuilder::class),
            $this->createStub(WhatsNewBuilder::class),
            $this->createStub(AlertBuilder::class),
            $this->createStub(ShortcutBuilder::class),
            $this->createStub(ConfigServiceInterface::class),
            $this->createStub(ScriptAdminRegistry::class),
            $stylesheetManagementRegistry,
            $this->createStub(FormThemeRegistry::class),
            $this->createStub(TranslatorInterface::class),
            $debug,
        );
    }

    // In dev, each bundle-contributed management stylesheet is added separately, for instant reload on every CSS edit
    public function testConfigureAssetsAddsEachManagementStylesheetSeparatelyInDebug(): void
    {
        $controller = $this->createController(true, ['bundles/c975lconfig/css/management.min.css']);

        $cssPaths = array_keys($controller->configureAssets()->getAsDto()->getCssAssets());

        $this->assertContains('bundles/c975lconfig/css/management.min.css', $cssPaths);
        $this->assertNotContains('bundles/build/admin.css', $cssPaths);
    }

    // Outside debug, links to the single file compiled by StylesheetCacheWarmer (c975L/UiBundle) instead
    // of the per-bundle list
    public function testConfigureAssetsAddsCompiledAdminStylesheetWhenNotDebug(): void
    {
        $controller = $this->createController(false, ['bundles/c975lconfig/css/management.min.css']);

        $cssPaths = array_keys($controller->configureAssets()->getAsDto()->getCssAssets());

        $this->assertContains('bundles/build/admin.css', $cssPaths);
        $this->assertNotContains('bundles/c975lconfig/css/management.min.css', $cssPaths);
    }
}
