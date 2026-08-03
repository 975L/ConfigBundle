<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\ScaffoldInstallCommand;
use c975L\ConfigBundle\Service\ScaffoldInstaller;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ScaffoldInstallCommandTest extends TestCase
{
    // ScaffoldInstaller is only ever called once per execute(), no further expectations needed here
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReportsCopiedBackedUpAndSkippedCounts(): void
    {
        $scaffoldInstaller = $this->createStub(ScaffoldInstaller::class);
        $scaffoldInstaller->method('install')->willReturn(['copied' => 3, 'backedUp' => 1, 'skipped' => 5, 'files' => [], 'unmatched' => []]);
        $tester = new CommandTester(new ScaffoldInstallCommand($scaffoldInstaller));

        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('3 file(s) copied, 1 backed up into existingFiles/, 5 already up to date.', $tester->getDisplay());
    }

    // themeImportReminder() non-null: the app.css @import still needs to be added by hand
    public function testExecuteDisplaysTheThemeImportReminderWhenPresent(): void
    {
        $scaffoldInstaller = $this->createConfiguredStub(ScaffoldInstaller::class, [
            'install' => ['copied' => 1, 'backedUp' => 0, 'skipped' => 0, 'files' => [], 'unmatched' => []],
            'themeImportReminder' => 'Add @import url("./themes/site.css"); to assets/styles/app.css.',
        ]);
        $tester = new CommandTester(new ScaffoldInstallCommand($scaffoldInstaller));

        $tester->execute([]);

        $this->assertStringContainsString('themes/site.css', $tester->getDisplay());
    }

    // A regression silently dropping either option would overwrite files the site diverged from, so the call itself is what is asserted here, the resulting behaviour being ScaffoldInstallerTest's job
    public function testExecutePassesThePathRestrictionAndTheDryRunFlagToTheInstaller(): void
    {
        $scaffoldInstaller = $this->createMock(ScaffoldInstaller::class);
        $scaffoldInstaller
            ->expects($this->once())
            ->method('install')
            ->with(['src/Scheduler', 'tests/Scheduler'], true)
            ->willReturn(['copied' => 0, 'backedUp' => 0, 'skipped' => 0, 'files' => [], 'unmatched' => []])
        ;
        $tester = new CommandTester(new ScaffoldInstallCommand($scaffoldInstaller));

        $tester->execute(['--path' => ['src/Scheduler', 'tests/Scheduler'], '--dry-run' => true]);
    }

    // Which files would be overwritten is the whole point of a dry run, a count alone saying nothing
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteListsTheFilesAndStatesNothingWasWrittenOnADryRun(): void
    {
        $scaffoldInstaller = $this->createStub(ScaffoldInstaller::class);
        $scaffoldInstaller->method('install')->willReturn([
            'copied' => 2,
            'backedUp' => 1,
            'skipped' => 0,
            'files' => ['src/Scheduler/MaintenanceSchedule.php', 'tests/Scheduler/MaintenanceScheduleTest.php'],
            'unmatched' => [],
        ]);
        $tester = new CommandTester(new ScaffoldInstallCommand($scaffoldInstaller));

        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('src/Scheduler/MaintenanceSchedule.php', $display);
        $this->assertStringContainsString('tests/Scheduler/MaintenanceScheduleTest.php', $display);
        $this->assertStringContainsString('Nothing was written.', $display);
    }

    // Zero counts and a green success are what a propagation scripted over a dozen sites would show on a typo'd path, so the run has to fail out loud instead
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFailsWhenAPathMatchedNoScaffoldFile(): void
    {
        $scaffoldInstaller = $this->createStub(ScaffoldInstaller::class);
        $scaffoldInstaller->method('install')->willReturn([
            'copied' => 0,
            'backedUp' => 0,
            'skipped' => 0,
            'files' => [],
            'unmatched' => ['src/Sheduler'],
        ]);
        $tester = new CommandTester(new ScaffoldInstallCommand($scaffoldInstaller));

        $statusCode = $tester->execute(['--path' => ['src/Sheduler']]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('src/Sheduler', $tester->getDisplay());
    }
}
