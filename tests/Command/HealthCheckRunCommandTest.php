<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\HealthCheckRunCommand;
use c975L\ConfigBundle\Management\HealthCheckRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class HealthCheckRunCommandTest extends TestCase
{
    public function testExecuteRunsEveryProviderWithoutTheKindOption(): void
    {
        $healthCheckRunner = $this->createMock(HealthCheckRunner::class);
        $healthCheckRunner->expects($this->once())->method('run')->with([])->willReturn(['pagespeed' => 3]);

        $tester = new CommandTester(new HealthCheckRunCommand($healthCheckRunner));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('pagespeed : 3 résultat(s) enregistré(s)', $tester->getDisplay());
    }

    public function testExecutePassesTheKindOptionThrough(): void
    {
        $healthCheckRunner = $this->createMock(HealthCheckRunner::class);
        $healthCheckRunner->expects($this->once())->method('run')->with(['wave'])->willReturn(['wave' => 1]);

        $tester = new CommandTester(new HealthCheckRunCommand($healthCheckRunner));
        $tester->execute(['--kind' => ['wave']]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testExecuteWarnsWhenNoProviderRan(): void
    {
        $healthCheckRunner = $this->createStub(HealthCheckRunner::class);
        $healthCheckRunner->method('run')->willReturn([]);

        $tester = new CommandTester(new HealthCheckRunCommand($healthCheckRunner));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Aucun HealthCheckProvider enregistré', $tester->getDisplay());
    }
}
