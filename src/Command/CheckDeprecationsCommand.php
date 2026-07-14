<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'c975l:deprecations:check',
    description: 'Groups the deprecations logged by Monolog\'s "deprecation" channel and flags the ones found in an installed c975L bundle'
)]
class CheckDeprecationsCommand extends Command
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $defaultEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('env', InputArgument::OPTIONAL, 'Environment whose deprecations log to read', $this->defaultEnv);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = $input->getArgument('env');
        $logFile = $this->projectDir . "/var/log/{$env}.deprecations.log";

        if (!is_file($logFile)) {
            $io->warning([
                'Fichier introuvable : ' . $logFile,
                'Vérifiez que config/packages/monolog.yaml isole le canal "deprecation" dans son propre handler, et qu\'au moins une requête a été faite en env ' . $env . '.',
            ]);

            return Command::SUCCESS;
        }

        // Extracts the human-readable message from each Monolog line, ignoring the trailing JSON context
        $messages = [];
        foreach (file($logFile) as $line) {
            if (!preg_match('/^\[[^\]]+\]\s+deprecation\.\w+:\s+(.+?)\s+\{"exception"/', $line, $m)) {
                continue;
            }
            $message = trim($m[1]);
            $messages[$message] = ($messages[$message] ?? 0) + 1;
        }

        if (!$messages) {
            $io->success('Aucune dépréciation trouvée dans ' . $logFile . '.');

            return Command::SUCCESS;
        }

        $bundleDirs = array_filter(glob($this->projectDir . '/vendor/c975l/*') ?: [], 'is_dir');
        $report = $this->buildReport($messages, $bundleDirs);

        foreach ($report as $entry) {
            $io->section(sprintf('[%dx] %s', $entry['count'], $entry['message']));
            if ($entry['hits']) {
                $io->warning('ACTIONNABLE - trouvé dans vos bundles c975L :');
                $io->listing($entry['hits']);
            } else {
                $io->text('Non localisé dans vendor/c975l - vient probablement d\'un package tiers ou de l\'app elle-même.');
            }
        }

        $io->success(sprintf('%d dépréciation(s) unique(s), %d occurrence(s) au total.', count($report), array_sum($messages)));

        return Command::SUCCESS;
    }

    // Cross-references each unique deprecation message against the installed c975L bundles' source,
    // sorted actionable-first then by frequency
    private function buildReport(array $messages, array $bundleDirs): array
    {
        $report = [];
        foreach ($messages as $message => $count) {
            $hits = [];
            foreach ($this->extractTokens($message) as $token) {
                foreach ($bundleDirs as $dir) {
                    $found = shell_exec(sprintf('grep -Frl %s %s 2>/dev/null', escapeshellarg($token), escapeshellarg($dir . '/src')));
                    foreach (array_filter(explode("\n", trim((string) $found))) as $file) {
                        $hits[basename($dir) . ' -> ' . str_replace($this->projectDir . '/', '', $file)] = true;
                    }
                }
            }

            $report[] = ['message' => $message, 'count' => $count, 'hits' => array_keys($hits)];
        }

        usort($report, fn (array $a, array $b) => (count($b['hits']) <=> count($a['hits'])) ?: ($b['count'] <=> $a['count']));

        return $report;
    }

    // Candidate tokens: fully-qualified class names and composer package names quoted in the message,
    // plus each FQCN's parent namespace - code that imports it via "use Foo\Bar\Annotation as X;" and
    // references "X\Uploadable" never spells out the full "Foo\Bar\Annotation\Uploadable" string,
    // only the "use" line does
    private function extractTokens(string $message): array
    {
        preg_match_all('/"([A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)+)"/', $message, $fqcnMatches);
        preg_match_all('/\b([a-z0-9_-]+\/[a-z0-9_-]+)\b/', $message, $pkgMatches);
        $tokens = array_merge($fqcnMatches[1], $pkgMatches[1]);

        foreach ($fqcnMatches[1] as $fqcn) {
            $parts = explode('\\', $fqcn);
            if (count($parts) > 1) {
                array_pop($parts);
                $tokens[] = implode('\\', $parts);
            }
        }

        return array_unique($tokens);
    }
}
