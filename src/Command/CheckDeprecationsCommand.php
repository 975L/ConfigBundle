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
    description: 'Groups the deprecations logged by Monolog\'s "deprecation" channel and flags the ones found in the app\'s own src/ or an installed c975L bundle'
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

        $sourceDirs = ['app' => $this->projectDir . '/src'];
        foreach (array_filter(glob($this->projectDir . '/vendor/c975l/*') ?: [], 'is_dir') as $dir) {
            $sourceDirs[basename($dir)] = $dir . '/src';
        }
        // Only messages tied to app/c975L source (confirmed or possible) are worth surfacing - one with neither is surely a third-party package's own concern, nothing we can act on
        $report = array_values(array_filter(
            $this->buildReport($messages, $sourceDirs),
            fn (array $entry) => $entry['hits'] || $entry['possibleHits']
        ));

        if (!$report) {
            $io->success('Aucune dépréciation actionnable trouvée dans ' . $logFile . ' - elles viennent toutes de packages tiers.');

            return Command::SUCCESS;
        }

        foreach ($report as $entry) {
            $io->section(sprintf('[%dx] %s', $entry['count'], $entry['message']));
            if ($entry['hits']) {
                $io->warning('ACTIONNABLE - trouvé dans votre code (app ou bundle c975L) :');
                $io->listing($entry['hits']);
            }
            if ($entry['possibleHits']) {
                $io->note('À vérifier - namespace de la classe dépréciée retrouvé, sans certitude que ce soit elle qui est utilisée :');
                $io->listing($entry['possibleHits']);
            }
        }

        $io->success(sprintf(
            '%d dépréciation(s) actionnable(s), %d occurrence(s) au total.',
            count($report),
            array_sum(array_column($report, 'count'))
        ));

        return Command::SUCCESS;
    }

    // Cross-references each unique deprecation message against the app's own src/ and the installed c975L bundles' source, sorted actionable-first then by frequency
    private function buildReport(array $messages, array $sourceDirs): array
    {
        $report = [];
        foreach ($messages as $message => $count) {
            $hits = [];
            $possibleHits = [];
            foreach ($this->extractTokens($message) as $token => $exact) {
                foreach ($sourceDirs as $label => $dir) {
                    if (!is_dir($dir)) {
                        continue;
                    }
                    $found = shell_exec(sprintf('grep -Frl %s %s 2>/dev/null', escapeshellarg($token), escapeshellarg($dir)));
                    foreach (array_filter(explode("\n", trim((string) $found))) as $file) {
                        $key = $label . ' -> ' . str_replace($this->projectDir . '/', '', $file);
                        if ($exact) {
                            $hits[$key] = true;
                        } else {
                            $possibleHits[$key] = true;
                        }
                    }
                }
            }

            // A namespace-only match on a file already confirmed by an exact FQCN match adds no signal
            $possibleHits = array_diff_key($possibleHits, $hits);

            $report[] = [
                'message' => $message,
                'count' => $count,
                'hits' => array_keys($hits),
                'possibleHits' => array_keys($possibleHits),
            ];
        }

        usort(
            $report,
            fn (array $a, array $b) => (count($b['hits']) <=> count($a['hits']))
                ?: (count($b['possibleHits']) <=> count($a['possibleHits']))
                ?: ($b['count'] <=> $a['count'])
        );

        return $report;
    }

    // Candidate tokens: fully-qualified class names quoted in the message are "exact" (high-confidence) matches. Composer package names and each FQCN's parent namespace are kept as lower-confidence tokens - code that imports a class via "use Foo\Bar\Annotation as X;" and references "X\Uploadable" never spells out the full "Foo\Bar\Annotation\Uploadable" string, only the "use" line does, and a bundle can mention a package name (e.g. in a comment or composer.json) without using the specific deprecated class it ships - both match just as easily without proving real usage, hence "possible" and not "actionable"
    private function extractTokens(string $message): array
    {
        preg_match_all('/"([A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)+)"/', $message, $fqcnMatches);
        preg_match_all('/\b([a-z0-9_-]+\/[a-z0-9_-]+)\b/', $message, $pkgMatches);

        $tokens = [];
        foreach ($fqcnMatches[1] as $token) {
            $tokens[$token] = true;
        }
        // Composer package names (e.g. "symfony/maker-bundle") are too generic to count as exact: a bundle mentioning the package name in a comment or composer.json, without actually using the deprecated class, matches just as easily
        foreach ($pkgMatches[1] as $token) {
            if (!isset($tokens[$token])) {
                $tokens[$token] = false;
            }
        }

        foreach ($fqcnMatches[1] as $fqcn) {
            $parts = explode('\\', $fqcn);
            if (count($parts) > 1) {
                array_pop($parts);
                $namespace = implode('\\', $parts);
                if (!isset($tokens[$namespace])) {
                    $tokens[$namespace] = false;
                }
            }
        }

        return $tokens;
    }
}
