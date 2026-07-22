<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle;

use c975L\ConfigBundle\DependencyInjection\Compiler\TaggedInterfacePass;
use c975L\ConfigBundle\Management\AlertProviderInterface;
use c975L\ConfigBundle\Management\ImportProviderInterface;
use c975L\ConfigBundle\Management\LinkableRouteProviderInterface;
use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\ConfigBundle\Management\ProcedureProviderInterface;
use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use c975L\ConfigBundle\Management\ThemePresetProviderInterface;
use c975L\ConfigBundle\Management\WhatsNewProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class c975LConfigBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TaggedInterfacePass(MenuProviderInterface::class, 'c975l.management_menu_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(ProcedureProviderInterface::class, 'c975l.procedure_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(WhatsNewProviderInterface::class, 'c975l.whatsnew_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(AlertProviderInterface::class, 'c975l.alert_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(ShortcutProviderInterface::class, 'c975l.shortcut_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(ImportProviderInterface::class, 'c975l.import_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(LinkableRouteProviderInterface::class, 'c975l.linkable_route_provider'));
        $container->addCompilerPass(new TaggedInterfacePass(ThemePresetProviderInterface::class, 'c975l.theme_preset_provider'));
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
