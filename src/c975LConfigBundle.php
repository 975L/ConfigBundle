<?php

/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\ConfigBundle\DependencyInjection\Compiler\TaggedInterfacePass;
use c975L\ConfigBundle\Management\AlertProviderInterface;
use c975L\ConfigBundle\Management\DashboardWidgetProviderInterface;
use c975L\ConfigBundle\Management\DevProfilePathProviderInterface;
use c975L\ConfigBundle\Management\EssentialActionProviderInterface;
use c975L\ConfigBundle\Management\ExportProviderInterface;
use c975L\ConfigBundle\Management\GuidedProjectProviderInterface;
use c975L\ConfigBundle\Management\HealthCheckAdviceProviderInterface;
use c975L\ConfigBundle\Management\HealthCheckProviderInterface;
use c975L\ConfigBundle\Management\ImportmapProviderInterface;
use c975L\ConfigBundle\Management\ImportProviderInterface;
use c975L\ConfigBundle\Management\LinkableRouteProviderInterface;
use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\ConfigBundle\Management\ProcedureProviderInterface;
use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use c975L\ConfigBundle\Management\SitemapProviderInterface;
use c975L\ConfigBundle\Management\StatusProviderInterface;
use c975L\ConfigBundle\Management\WhatsNewProviderInterface;
use c975L\ConfigBundle\Scheduler\MaintenanceTaskProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class c975LConfigBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $containerConfigurator, ContainerBuilder $container): void
    {
        // First JS/CSS ConfigBundle ships itself (the onboarding tour, see assets/controllers-admin.js) - mirrors UiBundle's own asset_mapper path registration
        $container->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    __DIR__ . '/../assets' => '@c975l/config-bundle',
                ],
            ],
        ]);

        // Then the user entity every c975L bundle relates to: they map against Contract\UserInterface, Doctrine resolves it to the application's own App\Entity\User. Guarded because a bundle checkout running its own tests has no DoctrineBundle registered
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'resolve_target_entities' => [
                        UserInterface::class => 'App\Entity\User',
                    ],
                ],
            ]);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TaggedInterfacePass(MenuProviderInterface::class, 'c975l.management_menu_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(ProcedureProviderInterface::class, 'c975l.procedure_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(WhatsNewProviderInterface::class, 'c975l.whatsnew_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(AlertProviderInterface::class, 'c975l.alert_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(ShortcutProviderInterface::class, 'c975l.shortcut_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(ImportProviderInterface::class, 'c975l.import_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(ExportProviderInterface::class, 'c975l.export_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(LinkableRouteProviderInterface::class, 'c975l.linkable_route_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(EssentialActionProviderInterface::class, 'c975l.essential_action_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(GuidedProjectProviderInterface::class, 'c975l.guided_project_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(DashboardWidgetProviderInterface::class, 'c975l.dashboard_widget_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(HealthCheckProviderInterface::class, 'c975l.health_check_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(HealthCheckAdviceProviderInterface::class, 'c975l.health_check_advice_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(ImportmapProviderInterface::class, 'c975l.importmap_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(SitemapProviderInterface::class, 'c975l.sitemap_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(StatusProviderInterface::class, 'c975l.status_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(MaintenanceTaskProviderInterface::class, 'c975l.maintenance_task_provider'));
        // Only ever has anything to collect in dev, every implementation being marked #[When('dev')] - the pass itself stays registered in every environment, it simply tags nothing in prod
        $container->addCompilerPass(new TaggedInterfacePass(DevProfilePathProviderInterface::class, 'c975l.dev_profile_path_provider'));
    }

    public function loadExtension(array $config, ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        $containerConfigurator->import('../config/services.yaml');
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
