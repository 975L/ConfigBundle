<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Attribute\AsHealthCheck;
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
        // Pins one run to named providers - handy from the command line, but a cron entry should ask for a frequency instead, so installing a bundle never means editing its schedule
        $this->addOption('kind', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Only run the given provider kind(s) (eg. --kind=wave), repeatable - omit to run every registered provider');
        // What the scheduler asks for: the providers declaring that cadence, whatever bundles happen to be installed - see AsHealthCheck
        $this->addOption('frequency', null, InputOption::VALUE_REQUIRED, sprintf('Only run the providers declaring that cadence (%s) - omit to run every registered provider', implode(', ', AsHealthCheck::FREQUENCIES)));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $frequency = $input->getOption('frequency');
        if (null !== $frequency && !\in_array($frequency, AsHealthCheck::FREQUENCIES, true)) {
            $io->error(sprintf('Unknown frequency "%s" - expected: %s.', $frequency, implode(', ', AsHealthCheck::FREQUENCIES)));

            return Command::INVALID;
        }

        $kinds = $input->getOption('kind');

        // A kind nobody provides used to be skipped without a word, so a cron entry naming a bundle that is not installed (or a provider since renamed) ran for nothing, week after week, and said "success"
        if ($unknown = array_diff($kinds, $this->healthCheckRunner->getKinds())) {
            $io->warning(sprintf(
                'Kind(s) with no registered provider, hence skipped: %s. Available: %s.',
                implode(', ', $unknown),
                implode(', ', $this->healthCheckRunner->getKinds()) ?: 'none',
            ));
        }

        $counts = $this->healthCheckRunner->run($kinds, $frequency);

        // Nothing ran: tells apart a site with no provider at all from a filter that matched none of them, which otherwise both read as "nothing to run"
        if (!$counts) {
            $io->warning($this->healthCheckRunner->getKinds()
                ? 'No provider matches the given filter - nothing to run.'
                : 'No HealthCheckProvider registered - nothing to run.');

            return Command::SUCCESS;
        }

        foreach ($counts as $kind => $count) {
            $io->writeln(sprintf('%s: %d result(s) recorded', $kind, $count));
        }

        $io->success('Health check completed.');

        return Command::SUCCESS;
    }
}
