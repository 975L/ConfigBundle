<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Console command to create config files, executed with 'config:create'
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class ConfigCreateCommand extends Command
{
    public function __construct(/**
     * Stores ConfigServiceInterface
     */
    private readonly ConfigServiceInterface $configService)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('config:create')
            ->setDescription('Creates the config files')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //Defines bundles default config
        $bundles = $this->configService->getBundles();
        foreach ($bundles as $bundle=> $name) {
            $bundleConfig = $this->configService->getBundleConfig($bundle);
            $parameters = get_object_vars($bundleConfig);
            $root = $parameters['configDataReserved']['roots'][0];
            unset($parameters['configDataReserved']);

            //Assigns default value for parameter if not defined
            $globalConfig = $this->configService->getGlobalConfig();
            foreach ($parameters as $key => $value) {
                $bundleConfig->$key = !isset($globalConfig[$root]) || !array_key_exists($key, $globalConfig[$root]) ? $value['default'] : $globalConfig[$root][$key];
            }
            $this->configService->setConfig($bundleConfig);
        }

        //Output data
        $io = new SymfonyStyle($input, $output);
        $io->title('c975L/ConfigBundle');
        $io->text('Creates/Updates config files');
        $io->success('Config files have been created/updated!');

        if (str_starts_with(Kernel::VERSION, '5')) {
            return Command::SUCCESS;
        }

        return 0;
    }
}
