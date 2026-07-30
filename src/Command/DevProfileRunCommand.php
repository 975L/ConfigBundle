<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Command;

use c975L\ConfigBundle\Management\DevProfileAnalyzer;
use c975L\ConfigBundle\Management\DevProfileRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'c975l:dev-profile:run',
    description: 'Profiles every page declared by a DevProfilePathProviderInterface against the local code and database, and lists what the dev toolbar would flag on each (n+1 queries, deprecations, missing translations...)'
)]
#[When('dev')]
class DevProfileRunCommand extends Command
{
    public function __construct(
        private readonly DevProfileRunner $devProfileRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Only profile the given path(s) (eg. --path=/pages/contact), repeatable - omit to profile every declared path');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Also list the pages that have nothing to fix, with their numbers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->devProfileRunner->run($input->getOption('path'));
        if (!$report) {
            $io->warning('Aucun chemin à profiler - aucun DevProfilePathProvider enregistré ?');

            return Command::SUCCESS;
        }

        $showAll = $input->getOption('all');
        $errors = 0;
        $warnings = 0;
        $clean = 0;

        foreach ($report as $entry) {
            $issues = $entry['issues'];
            $errors += \count(array_filter($issues, static fn (array $issue): bool => DevProfileAnalyzer::LEVEL_ERROR === $issue['level']));
            $warnings += \count(array_filter($issues, static fn (array $issue): bool => DevProfileAnalyzer::LEVEL_WARNING === $issue['level']));

            if (!$issues) {
                ++$clean;
                if (!$showAll) {
                    continue;
                }
            }

            $io->writeln(sprintf('<info>%s</info>%s', $entry['path'], $entry['label'] ? sprintf(' — %s', $entry['label']) : ''));
            $io->writeln(sprintf('  <comment>%s</comment>', $this->formatMetrics($entry['metrics'])));

            foreach ($issues as $issue) {
                $io->writeln(sprintf(
                    '  %s %-14s %s',
                    DevProfileAnalyzer::LEVEL_ERROR === $issue['level'] ? '<fg=red>ERREUR</>' : '<fg=yellow>ALERTE</>',
                    $issue['area'],
                    $issue['message']
                ));
            }

            $io->newLine();
        }

        $summary = sprintf('%d page(s) profilée(s), %d propre(s), %d erreur(s), %d alerte(s).', \count($report), $clean, $errors, $warnings);
        if ($errors > 0) {
            $io->error($summary);

            // Non-zero so the command can gate a pre-push hook, exactly like c975l:site:smoke-test does after a deployment
            return Command::FAILURE;
        }

        $warnings > 0 ? $io->warning($summary) : $io->success($summary);

        return Command::SUCCESS;
    }

    // The page's numbers, shown as context under its name whether or not it has anything to fix - timings included, but see DevProfileAnalyzer on why none of them is ever an offence
    private function formatMetrics(array $metrics): string
    {
        if (null !== ($metrics['error'] ?? null)) {
            return 'aucune mesure';
        }

        return sprintf(
            'HTTP %d · %d requêtes (%s ms) · %d templates (%s ms) · %d dépréciations · cache %d/%d · %s ms · %s Mo',
            $metrics['statusCode'],
            $metrics['queries'],
            $metrics['queryTime'],
            $metrics['templates'],
            $metrics['twigTime'],
            $metrics['deprecations'],
            $metrics['cacheHits'],
            $metrics['cacheHits'] + $metrics['cacheMisses'],
            $metrics['duration'],
            round($metrics['memory'] / 1024 / 1024, 1)
        );
    }
}
