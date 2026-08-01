<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\DevProfileRunCommand;
use c975L\ConfigBundle\Management\DevProfileAnalyzer;
use c975L\ConfigBundle\Management\DevProfileRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DevProfileRunCommandTest extends TestCase
{
    // One report entry, clean unless the test hands it issues - the numbers are only ever printed as context
    private function entry(string $path, ?string $label = null, array $issues = [], array $metricsOverrides = []): array
    {
        return [
            'path' => $path,
            'label' => $label,
            'metrics' => $metricsOverrides + [
                'error' => null,
                'statusCode' => 200,
                'queries' => 12,
                'queryTime' => 4.5,
                'transactions' => 1,
                'templates' => 30,
                'twigTime' => 18.0,
                'deprecations' => 0,
                'cacheHits' => 8,
                'cacheMisses' => 2,
                'duration' => 120.0,
                'memory' => 14680064,
            ],
            'issues' => $issues,
        ];
    }

    private function createTester(array $report): CommandTester
    {
        $runner = $this->createStub(DevProfileRunner::class);
        $runner->method('run')->willReturn($report);

        return new CommandTester(new DevProfileRunCommand($runner));
    }

    public function testExecuteRunsEveryDeclaredPathWithoutThePathOption(): void
    {
        $runner = $this->createMock(DevProfileRunner::class);
        $runner->expects($this->once())->method('run')->with([])->willReturn([$this->entry('/', 'Accueil')]);

        $tester = new CommandTester(new DevProfileRunCommand($runner));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testExecutePassesThePathOptionThrough(): void
    {
        $runner = $this->createMock(DevProfileRunner::class);
        $runner->expects($this->once())->method('run')->with(['/pages/contact'])->willReturn([$this->entry('/pages/contact', 'Contact')]);

        $tester = new CommandTester(new DevProfileRunCommand($runner));
        $tester->execute(['--path' => ['/pages/contact']]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testExecuteWarnsWhenNoPathWasProfiled(): void
    {
        $tester = $this->createTester([]);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Aucun chemin à profiler', $tester->getDisplay());
    }

    // A clean page is silent by default, the output being the list of what's left to fix
    public function testExecuteHidesACleanPageWithoutTheAllOption(): void
    {
        $tester = $this->createTester([$this->entry('/', 'Accueil')]);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringNotContainsString('Accueil', $tester->getDisplay());
        $this->assertStringContainsString('1 propre(s)', $tester->getDisplay());
    }

    public function testExecuteListsACleanPageAndItsNumbersWithTheAllOption(): void
    {
        $tester = $this->createTester([$this->entry('/', 'Accueil')]);
        $tester->execute(['--all' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('/ — Accueil', $display);
        $this->assertStringContainsString('HTTP 200', $display);
        $this->assertStringContainsString('12 requêtes (4.5 ms)', $display);
        $this->assertStringContainsString('14 Mo', $display);
    }

    public function testExecuteShowsAWarningAndStillSucceeds(): void
    {
        $tester = $this->createTester([$this->entry('/', 'Accueil', [
            ['level' => DevProfileAnalyzer::LEVEL_WARNING, 'area' => 'Dépréciations', 'message' => '2 dépréciation(s)'],
        ])]);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('ALERTE', $tester->getDisplay());
        $this->assertStringContainsString('2 dépréciation(s)', $tester->getDisplay());
        $this->assertStringContainsString('1 alerte(s)', $tester->getDisplay());
    }

    // Non-zero so the command can gate a pre-push hook
    public function testExecuteFailsAsSoonAsOnePageHasAnError(): void
    {
        $tester = $this->createTester([
            $this->entry('/', 'Accueil'),
            $this->entry('/pages/contact', 'Contact', [
                ['level' => DevProfileAnalyzer::LEVEL_ERROR, 'area' => 'Doctrine', 'message' => '31 requêtes identiques répétées (n+1)'],
            ]),
        ]);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('ERREUR', $tester->getDisplay());
        $this->assertStringContainsString('1 erreur(s)', $tester->getDisplay());
    }

    // A path the kernel couldn't answer at all has no numbers to show, and must not print a line of zeros reading like a measurement
    public function testExecuteSaysNothingWasMeasuredOnAKernelError(): void
    {
        $tester = $this->createTester([$this->entry('/', 'Accueil', [
            ['level' => DevProfileAnalyzer::LEVEL_ERROR, 'area' => 'Kernel', 'message' => 'La requête a échoué : Service introuvable'],
        ], ['error' => 'Service introuvable'])]);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('aucune mesure', $tester->getDisplay());
        $this->assertStringNotContainsString('HTTP', $tester->getDisplay());
    }

    public function testExecuteShowsAPathWithoutALabelOnItsOwn(): void
    {
        $tester = $this->createTester([$this->entry('/admin', null, [
            ['level' => DevProfileAnalyzer::LEVEL_WARNING, 'area' => 'Réponse', 'message' => 'Redirection (302), page non analysée'],
        ])]);
        $tester->execute([]);

        $this->assertStringContainsString('/admin', $tester->getDisplay());
        $this->assertStringNotContainsString('—', $tester->getDisplay());
    }
}
