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
use c975L\ConfigBundle\Contract\UserInterface;
use c975L\ConfigBundle\DependencyInjection\Compiler\TaggedInterfacePass;
use c975L\ConfigBundle\Management\AlertProviderInterface;
use c975L\ConfigBundle\Management\DashboardWidgetProviderInterface;
use c975L\ConfigBundle\Management\DevProfilePathProviderInterface;
use c975L\ConfigBundle\Management\EssentialActionProviderInterface;
use c975L\ConfigBundle\Management\ExportProviderInterface;
use c975L\ConfigBundle\Management\GuidedProjectProviderInterface;
use c975L\ConfigBundle\Management\ImportProviderInterface;
use c975L\ConfigBundle\Management\LinkableRouteProviderInterface;
use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\ConfigBundle\Management\ProcedureProviderInterface;
use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use c975L\ConfigBundle\Management\SitemapProviderInterface;
use c975L\ConfigBundle\Management\WhatsNewProviderInterface;
use c975L\ConfigBundle\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
        $container->register('import_provider', c975LConfigBundleTestImportProviderFixture::class);
        $container->register('export_provider', c975LConfigBundleTestExportProviderFixture::class);
        $container->register('procedure_provider', c975LConfigBundleTestProcedureProviderFixture::class);
        $container->register('linkable_route_provider', c975LConfigBundleTestLinkableRouteProviderFixture::class);
        $container->register('essential_action_provider', c975LConfigBundleTestEssentialActionProviderFixture::class);
        $container->register('dashboard_widget_provider', c975LConfigBundleTestDashboardWidgetProviderFixture::class);
        $container->register('sitemap_provider', c975LConfigBundleTestSitemapProviderFixture::class);
        $container->register('dev_profile_path_provider', c975LConfigBundleTestDevProfilePathProviderFixture::class);
        $container->register('guided_project_provider', c975LConfigBundleTestGuidedProjectProviderFixture::class);

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
        $this->assertTrue($container->getDefinition('import_provider')->hasTag('c975l.import_provider'));
        $this->assertTrue($container->getDefinition('export_provider')->hasTag('c975l.export_provider'));
        $this->assertTrue($container->getDefinition('procedure_provider')->hasTag('c975l.procedure_provider'));
        $this->assertTrue($container->getDefinition('linkable_route_provider')->hasTag('c975l.linkable_route_provider'));
        $this->assertTrue($container->getDefinition('essential_action_provider')->hasTag('c975l.essential_action_provider'));
        $this->assertTrue($container->getDefinition('dashboard_widget_provider')->hasTag('c975l.dashboard_widget_provider'));
        $this->assertTrue($container->getDefinition('sitemap_provider')->hasTag('c975l.sitemap_provider'));
        // Registered in every environment even though every implementation is #[When('dev')], the pass simply having nothing to tag in prod
        $this->assertTrue($container->getDefinition('dev_profile_path_provider')->hasTag('c975l.dev_profile_path_provider'));
        $this->assertTrue($container->getDefinition('guided_project_provider')->hasTag('c975l.guided_project_provider'));
    }

    // Mirrors how Symfony's own kernel invokes it (BundleExtension::load() builds the ContainerConfigurator and calls loadExtension() for us), so this also validates that config/services.yaml itself parses and wires without error
    public function testLoadExtensionImportsServicesYaml(): void
    {
        $container = new ContainerBuilder();

        (new c975LConfigBundle())->getContainerExtension()->load([], $container);

        $this->assertTrue($container->hasDefinition(ConfigService::class));
    }

    // The bundle's own JS/CSS (assets/controllers-admin.js) only reaches AssetMapper through this path registration
    public function testPrependExtensionRegistersTheBundleAssetMapperPath(): void
    {
        $container = new ContainerBuilder();

        (new c975LConfigBundle())->prependExtension($this->createStub(ContainerConfigurator::class), $container);

        $paths = $container->getExtensionConfig('framework')[0]['asset_mapper']['paths'];
        $this->assertSame(['@c975l/config-bundle'], array_values($paths));
        $this->assertSame(\dirname(__DIR__) . '/assets', realpath(array_key_first($paths)));
    }

    // What lets every c975L entity relate to Contract\UserInterface while Doctrine actually joins the application's own User
    public function testPrependExtensionMapsTheUserInterfaceOntoTheApplicationUserEntity(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new c975LConfigBundleTestDoctrineExtensionFixture());

        (new c975LConfigBundle())->prependExtension($this->createStub(ContainerConfigurator::class), $container);

        $this->assertSame(
            [['orm' => ['resolve_target_entities' => [UserInterface::class => 'App\Entity\User']]]],
            $container->getExtensionConfig('doctrine')
        );
    }

    // A bundle checkout running its own tests has no DoctrineBundle registered, and the mapping must not be prepended for an extension that isn't there
    public function testPrependExtensionSkipsTheDoctrineMappingWithoutTheDoctrineExtension(): void
    {
        $container = new ContainerBuilder();

        (new c975LConfigBundle())->prependExtension($this->createStub(ContainerConfigurator::class), $container);

        $this->assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testGetPathReturnsTheBundleRootDirectory(): void
    {
        $bundle = new c975LConfigBundle();

        $this->assertSame(\dirname(__DIR__), $bundle->getPath());
    }
}

// Stands in for DoctrineBundle's extension, only its alias mattering to prependExtension()
class c975LConfigBundleTestDoctrineExtensionFixture implements ExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function getAlias(): string
    {
        return 'doctrine';
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

class c975LConfigBundleTestImportProviderFixture implements ImportProviderInterface
{
    public function supportsImport(string $kind): bool
    {
        return false;
    }

    public function import(array $items, ?string $filesDir = null): array
    {
        return ['created' => 0, 'updated' => 0];
    }
}

class c975LConfigBundleTestExportProviderFixture implements ExportProviderInterface
{
    public function getKind(): string
    {
        return 'test_kind';
    }

    public function exportAll(): array
    {
        return ['items' => [], 'files' => []];
    }
}

class c975LConfigBundleTestLinkableRouteProviderFixture implements LinkableRouteProviderInterface
{
    public function getLinkableRoutes(): array
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

class c975LConfigBundleTestEssentialActionProviderFixture implements EssentialActionProviderInterface
{
    public function getEssentialActions(): array
    {
        return [];
    }
}

class c975LConfigBundleTestDashboardWidgetProviderFixture implements DashboardWidgetProviderInterface
{
    public function getDashboardWidgets(): array
    {
        return [];
    }
}

class c975LConfigBundleTestSitemapProviderFixture implements SitemapProviderInterface
{
    public function getSitemapName(): string
    {
        return 'test';
    }

    public function getUrls(): array
    {
        return [];
    }
}

class c975LConfigBundleTestDevProfilePathProviderFixture implements DevProfilePathProviderInterface
{
    public function getPaths(): array
    {
        return [];
    }
}

class c975LConfigBundleTestGuidedProjectProviderFixture implements GuidedProjectProviderInterface
{
    public function getGuidedProjects(): array
    {
        return [];
    }
}
