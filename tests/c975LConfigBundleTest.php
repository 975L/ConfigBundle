<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests;

use c975L\ConfigBundle\c975LConfigBundle;
use c975L\ConfigBundle\DependencyInjection\Compiler\TaggedInterfacePass;
use c975L\ConfigBundle\Management\AlertProviderInterface;
use c975L\ConfigBundle\Management\LinkableRouteProviderInterface;
use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\ConfigBundle\Management\ProcedureProviderInterface;
use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use c975L\ConfigBundle\Management\ThemePresetProviderInterface;
use c975L\ConfigBundle\Management\WhatsNewProviderInterface;
use c975L\ConfigBundle\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class c975LConfigBundleTest extends TestCase
{
    // Each provider mechanism (menu, whatsnew, alert, shortcut, linkable route) needs its own interface -> tag compiler pass; this exercises the passes end-to-end (via addCompilerPass + container compilation) rather than just asserting on pass count/type, since that's what actually breaks silently if an interface/tag pairing is ever mistyped
    public function testBuildRegistersACompilerPassTaggingServicesForEachProviderInterface(): void
    {
        $container = new ContainerBuilder();
        $container->register('menu_provider', c975LConfigBundleTestMenuProviderFixture::class);
        $container->register('whatsnew_provider', c975LConfigBundleTestWhatsNewProviderFixture::class);
        $container->register('alert_provider', c975LConfigBundleTestAlertProviderFixture::class);
        $container->register('shortcut_provider', c975LConfigBundleTestShortcutProviderFixture::class);
        $container->register('procedure_provider', c975LConfigBundleTestProcedureProviderFixture::class);
        $container->register('linkable_route_provider', c975LConfigBundleTestLinkableRouteProviderFixture::class);
        $container->register('theme_preset_provider', c975LConfigBundleTestThemePresetProviderFixture::class);

        (new c975LConfigBundle())->build($container);

        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            if ($pass instanceof TaggedInterfacePass) {
                $pass->process($container);
            }
        }

        $this->assertTrue($container->getDefinition('menu_provider')->hasTag('c975l.management_menu_provider'));
        $this->assertTrue($container->getDefinition('whatsnew_provider')->hasTag('c975l.whatsnew_provider'));
        $this->assertTrue($container->getDefinition('alert_provider')->hasTag('c975l.alert_provider'));
        $this->assertTrue($container->getDefinition('shortcut_provider')->hasTag('c975l.shortcut_provider'));
        $this->assertTrue($container->getDefinition('procedure_provider')->hasTag('c975l.procedure_provider'));
        $this->assertTrue($container->getDefinition('linkable_route_provider')->hasTag('c975l.linkable_route_provider'));
        $this->assertTrue($container->getDefinition('theme_preset_provider')->hasTag('c975l.theme_preset_provider'));
    }

    // Mirrors how Symfony's own kernel invokes it (BundleExtension::load() builds the ContainerConfigurator and calls loadExtension() for us), so this also validates that config/services.yaml itself parses and wires without error
    public function testLoadExtensionImportsServicesYaml(): void
    {
        $container = new ContainerBuilder();

        (new c975LConfigBundle())->getContainerExtension()->load([], $container);

        $this->assertTrue($container->hasDefinition(ConfigService::class));
    }

    public function testGetPathReturnsTheBundleRootDirectory(): void
    {
        $bundle = new c975LConfigBundle();

        $this->assertSame(\dirname(__DIR__), $bundle->getPath());
    }
}

// Own PSR-4 files (see TaggedInterfacePassTest for why): a class only ever defined as a side effect inside a test method can't be reflected by a consuming app's attribute route loader
class c975LConfigBundleTestMenuProviderFixture implements MenuProviderInterface
{
    public function getMenuSection(): array
    {
        return [];
    }

    public function getMenus(): array
    {
        return [];
    }

    public function getLinks(): array
    {
        return [];
    }
}

class c975LConfigBundleTestWhatsNewProviderFixture implements WhatsNewProviderInterface
{
    public function getEntries(): array
    {
        return [];
    }
}

class c975LConfigBundleTestAlertProviderFixture implements AlertProviderInterface
{
    public function getAlerts(): array
    {
        return [];
    }
}

class c975LConfigBundleTestShortcutProviderFixture implements ShortcutProviderInterface
{
    public function getShortcuts(): array
    {
        return [];
    }
}

class c975LConfigBundleTestLinkableRouteProviderFixture implements LinkableRouteProviderInterface
{
    public function getLinkableRoutes(): array
    {
        return [];
    }
}

class c975LConfigBundleTestThemePresetProviderFixture implements ThemePresetProviderInterface
{
    public function getPresets(): array
    {
        return [];
    }
}

class c975LConfigBundleTestProcedureProviderFixture implements ProcedureProviderInterface
{
    public function getProcedures(): array
    {
        return [];
    }
}
