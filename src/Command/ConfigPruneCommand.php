<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigDeclarationLocator;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:config:prune',
    description: 'Deletes config entries no configs*.json declares anymore. Lists them without deleting unless --force is given.'
)]
class ConfigPruneCommand extends Command
{
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ConfigDeclarationLocator $declarationLocator,
        private readonly ConfigServiceInterface $configService,
        private readonly EntityManagerInterface $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete the entries (without it, they are only listed)')
            ->setHelp(<<<'HELP'
                Lists the entries left in database that no bundle - nor the application itself - declares anymore:

                  <info>php %command.full_name%</info>

                Deletes them, after confirmation:

                  <info>php %command.full_name% --force</info>

                Deletion is irreversible and takes the stored value with it, so export your configs beforehand if in doubt.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // An empty vendor/ (install not finished, wrong directory...) would make every entry look undeclared: refuse rather than wipe the table
        if (empty($this->declarationLocator->findFiles())) {
            $io->error('No configs*.json found: nothing declares config entries here, refusing to consider them all as orphans.');

            return Command::FAILURE;
        }

        // A file that exists but can't be parsed declares none of its slugs, which would then all look like orphans: refuse until it is fixed
        $unreadable = $this->declarationLocator->findUnreadableFiles();

        if (!empty($unreadable)) {
            $io->error(array_merge(
                ['Unreadable configs*.json, refusing to consider the entries they declare as orphans:'],
                $unreadable,
            ));

            return Command::FAILURE;
        }

        $orphans = array_values(array_diff(
            $this->configRepository->findAllSlugs(),
            $this->declarationLocator->findDeclaredSlugs()
        ));

        if (empty($orphans)) {
            $io->success('No orphan config entry.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('%d config entrie(s) no longer declared', count($orphans)));
        $io->listing($orphans);

        if (!$input->getOption('force')) {
            $io->note('Nothing deleted. Re-run with --force to delete them.');

            return Command::SUCCESS;
        }

        if ($input->isInteractive() && !$io->confirm(sprintf('Delete these %d entrie(s) and their values?', count($orphans)), false)) {
            $io->note('Aborted, nothing deleted.');

            return Command::SUCCESS;
        }

        foreach ($this->configRepository->findBy(['slug' => $orphans]) as $config) {
            $this->manager->remove($config);
        }

        $this->manager->flush();
        $this->configService->invalidateCache();

        $io->success(sprintf('%d entrie(s) deleted.', count($orphans)));

        return Command::SUCCESS;
    }
}
