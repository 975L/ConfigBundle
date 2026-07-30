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
use c975L\ConfigBundle\Management\DashboardWidgetBuilder;
use c975L\ConfigBundle\Management\EssentialActionBuilder;
use c975L\ConfigBundle\Management\GuidedProjectBuilder;
use c975L\ConfigBundle\Management\GuidedProjectMountBuilder;
use c975L\ConfigBundle\Management\MenuBuilder;
use c975L\ConfigBundle\Management\OnboardingStepBuilder;
use c975L\ConfigBundle\Management\ShortcutBuilder;
use c975L\ConfigBundle\Management\WhatsNewBuilder;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\UiBundle\Registry\FormThemeRegistry;
use c975L\UiBundle\Registry\ScriptAdminRegistry;
use c975L\UiBundle\Registry\StylesheetManagementRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Contracts\Translation\TranslatorInterface;

class DashboardControllerTest extends TestCase
{
    private function createController(bool $debug, array $managementStylesheets, array $configs = [], string $guidedProjectMount = ''): DashboardController
    {
        $guidedProjectMountBuilder = $this->createStub(GuidedProjectMountBuilder::class);
        $guidedProjectMountBuilder->method('getHtml')->willReturn($guidedProjectMount);

        $stylesheetManagementRegistry = $this->createStub(StylesheetManagementRegistry::class);
        $stylesheetManagementRegistry->method('all')->willReturn($managementStylesheets);

        // site-role-admin is always set: configureMenuItems() passes it straight to setPermission(), which rejects null
        $configs += ['site-role-admin' => 'ROLE_ADMIN'];
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $key) => $configs[$key] ?? null);

        // Stands in for the real asset packages, which turn a logical path into its digested public URL
        $packages = $this->createStub(Packages::class);
        $packages->method('getUrl')->willReturnCallback(
            fn (string $path) => str_starts_with($path, 'http') ? $path : '/assets/' . $path . '?digest'
        );

        return new DashboardController(
            $this->createStub(MenuBuilder::class),
            $this->createStub(WhatsNewBuilder::class),
            $this->createStub(AlertBuilder::class),
            $this->createStub(ShortcutBuilder::class),
            $this->createStub(EssentialActionBuilder::class),
            $this->createStub(DashboardWidgetBuilder::class),
            $this->createStub(OnboardingStepBuilder::class),
            $this->createStub(GuidedProjectBuilder::class),
            $guidedProjectMountBuilder,
            $configService,
            $this->createStub(ScriptAdminRegistry::class),
            $stylesheetManagementRegistry,
            $this->createStub(FormThemeRegistry::class),
            $this->createStub(TranslatorInterface::class),
            $packages,
            $debug,
        );
    }

    private function getMadeByLogoSrc(DashboardController $controller): ?string
    {
        foreach ($controller->configureMenuItems() as $item) {
            $label = $item->getAsDto()->getLabel();
            if (is_string($label) && preg_match('/<img src="([^"]*)"/', $label, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    // In dev, each bundle-contributed management stylesheet is added separately, for instant reload on every CSS edit
    public function testConfigureAssetsAddsEachManagementStylesheetSeparatelyInDebug(): void
    {
        $controller = $this->createController(true, ['bundles/c975lconfig/css/management.min.css']);

        $cssPaths = array_keys($controller->configureAssets()->getAsDto()->getCssAssets());

        $this->assertContains('bundles/c975lconfig/css/management.min.css', $cssPaths);
        $this->assertNotContains('bundles/build/admin.css', $cssPaths);
    }

    // Outside debug, links to the single file compiled by StylesheetCacheWarmer (c975L/UiBundle) instead of the per-bundle list
    public function testConfigureAssetsAddsCompiledAdminStylesheetWhenNotDebug(): void
    {
        $controller = $this->createController(false, ['bundles/c975lconfig/css/management.min.css']);

        $cssPaths = array_keys($controller->configureAssets()->getAsDto()->getCssAssets());

        $this->assertContains('bundles/build/admin.css', $cssPaths);
        $this->assertNotContains('bundles/c975lconfig/css/management.min.css', $cssPaths);
    }

    // The guided-project panel has to survive the page loads a project walks the user through, so its mount element goes into the body of every admin page rather than into the dashboard template alone - EasyAdmin renders these on all of them, which spares an override of its layout
    public function testConfigureAssetsMountsTheGuidedProjectPanelOnEveryAdminPage(): void
    {
        $controller = $this->createController(true, [], [], '<div data-controller="guided-project"></div>');

        $this->assertContains('<div data-controller="guided-project"></div>', $controller->configureAssets()->getAsDto()->getBodyContents());
    }

    // The label is raw HTML, so a relative path must go through the asset packages, not /management/
    public function testMadeByLogoPathIsResolvedThroughTheAssetPackages(): void
    {
        $controller = $this->createController(false, [], [
            'site-made-by-logo' => 'images/logo-975l.svg',
            'site-made-by-url' => 'https://975l.com',
        ]);

        $this->assertSame('/assets/images/logo-975l.svg?digest', $this->getMadeByLogoSrc($controller));
    }

    // A config still holding the absolute URL used before must keep working
    public function testMadeByLogoAbsoluteUrlIsLeftUntouched(): void
    {
        $controller = $this->createController(false, [], [
            'site-made-by-logo' => 'https://975l.com/images/logo-975l.svg',
            'site-made-by-url' => 'https://975l.com',
        ]);

        $this->assertSame('https://975l.com/images/logo-975l.svg', $this->getMadeByLogoSrc($controller));
    }

    public function testNoMadeByMenuItemWhenEitherConfigIsEmpty(): void
    {
        $controller = $this->createController(false, [], ['site-made-by-logo' => 'images/logo-975l.svg']);

        $this->assertNull($this->getMadeByLogoSrc($controller));
    }
}
