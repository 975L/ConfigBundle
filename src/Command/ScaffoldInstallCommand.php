<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Service\ScaffoldInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Standalone, re-runnable equivalent of the scaffold-install step of c975l:site:create (which is gated by a one-time lock file). Meant to pull in a bundle's scaffold/{src,templates,tests,translations} after installing it into an *existing* site (e.g. "composer require c975l/shop-bundle" later on) - ScaffoldInstaller is idempotent, so running this again on an unmodified project is a no-op.
#[AsCommand(
    name: 'c975l:scaffold:install',
    description: 'Installs (or refreshes) every installed c975L bundle\'s scaffold files into the project'
)]
class ScaffoldInstallCommand extends Command
{
    public function __construct(
        private readonly ScaffoldInstaller $scaffoldInstaller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict the run to these relative paths (--path=src/Scheduler, repeatable), instead of the whole scaffold of every installed bundle')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be copied and backed up, write nothing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $result = $this->scaffoldInstaller->install($input->getOption('path'), $dryRun);

        // What would be overwritten is the whole point of the dry run, a count alone saying nothing about which files diverged
        if ($dryRun && $result['files']) {
            $io->listing($result['files']);
        }

        $message = sprintf(
            $dryRun
                ? '%d file(s) to copy, of which %d to back up into existingFiles/, %d already up to date. Nothing was written.'
                : '%d file(s) copied, %d backed up into existingFiles/, %d already up to date.',
            $result['copied'],
            $result['backedUp'],
            $result['skipped']
        );

        // A --path naming nothing reports zero everywhere, which reads exactly like an already up-to-date site: a typo, or a path given as it stands in the bundle ('scaffold/src/Scheduler'), would otherwise go unnoticed across the dozen sites it was being propagated to - hence the non-zero exit code too, so a loop over them stops instead of scrolling green
        if ($result['unmatched']) {
            $io->warning($message);
            $io->error(sprintf('No scaffold file matches: %s', implode(', ', $result['unmatched'])));

            return Command::FAILURE;
        }

        $io->success($message);

        $reminder = $this->scaffoldInstaller->themeImportReminder();
        if (null !== $reminder) {
            $io->note($reminder);
        }

        return Command::SUCCESS;
    }
}
