<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Management\HealthCheckRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: HealthCheckRunCommand::NAME,
    description: 'Runs every registered HealthCheckProvider (PageSpeed Insights, W3C validator...) and persists the results for the "Health check" dashboard page'
)]
class HealthCheckRunCommand extends Command
{
    // Named here rather than in the attribute alone so the dashboard's "Run health check now" button can queue this very command (see HealthCheckController::run()) without repeating the string
    public const NAME = 'c975l:health-check:run';

    public function __construct(
        private readonly HealthCheckRunner $healthCheckRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // Lets the scheduler run a costly/paid provider (eg. "wave") on its own, less frequent cron entry - see HealthCheckRunner::run()
        $this->addOption('kind', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Only run the given provider kind(s) (eg. --kind=wave), repeatable - omit to run every registered provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $counts = $this->healthCheckRunner->run($input->getOption('kind'));

        if (!$counts) {
            $io->warning('Aucun HealthCheckProvider enregistré - rien à exécuter.');

            return Command::SUCCESS;
        }

        foreach ($counts as $kind => $count) {
            $io->writeln(sprintf('%s : %d résultat(s) enregistré(s)', $kind, $count));
        }

        $io->success('Health check terminé.');

        return Command::SUCCESS;
    }
}
