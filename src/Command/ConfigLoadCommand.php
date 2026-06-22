<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command to load default config values in the database, executed with 'config:load'
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2017 975L <contact@975l.com>
 */

// Examples of usage:
// php bin/console c975l:config:load 'vendor/c975l/config-bundle/config/configs.json'
// php bin/console c975l:config:load 'vendor/c975l/site-bundle/config/configs.json'
// php bin/console c975l:config:load 'vendor/c975l/contactform-bundle/config/configs.json'
// php bin/console c975l:config:load 'vendor/c975l/shop-bundle/config/configs.json'

#[AsCommand(
    name: 'c975l:config:load',
    description: 'Loads default config values in the database'
)]
class ConfigLoadCommand extends Command
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('json', InputArgument::REQUIRED, 'Absolute path to the JSON file containing default config values')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = $input->getArgument('json');

        if (!file_exists($path)) {
            $io->error(sprintf('File not found: %s', $path));
            return Command::FAILURE;
        }

        $this->configService->loadDefaultConfig($path);

        $io->success('Default config values loaded.');

        return Command::SUCCESS;
    }
}
