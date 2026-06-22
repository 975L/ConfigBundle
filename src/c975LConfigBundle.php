<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle;

use c975L\ConfigBundle\DependencyInjection\Compiler\MenuProviderPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class c975LConfigBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MenuProviderPass());
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
