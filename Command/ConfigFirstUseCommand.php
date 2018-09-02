<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;

/**
 * Console command to create config files for first use, executed with 'config:create-first-use'
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class ConfigFirstUseCommand extends ContainerAwareCommand
{
    /**
     * Stores ConfigServiceInterface
     * @var ConfigServiceInterface
     */
    private $configService;

    public function __construct(ConfigServiceInterface $configService)
    {
        parent::__construct();
        $this->configService = $configService;
    }

    protected function configure()
    {
        $this
            ->setName('config:create-first-use')
            ->setDescription('Creates the config files before first use')
            ->addArgument('bundle', InputArgument::REQUIRED, 'Bundle name, as defined in composer.json')
            ->addArgument('root', InputArgument::REQUIRED, 'Root name, as you will use it')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $bundle = $input->getArgument('bundle');
        $root = $input->getArgument('root');

        //Defines bundle default config
        $bundleConfig = $this->configService->getBundleConfig($bundle);
        $parameters = get_object_vars($bundleConfig);
        unset($parameters['configDataReserved']);

        //Assigns default value for specified root
        foreach ($parameters as $key => $value) {
            if ($root == $value['root']) {
                $bundleConfig->$key = $value['default'];
            //Removes property as not part of the called root
            } else {
                unset($bundleConfig->$key);
            }
        }
        $this->configService->setConfig($bundleConfig);

        //Output data
        $io = new SymfonyStyle($input, $output);
        $io->title('c975L/ConfigBundle');
        $io->text('Create config files before first use');
        $io->success('Config files for ' . $root . ' have been created!');
    }
}
