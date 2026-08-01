<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Scheduler;

use c975L\ConfigBundle\Scheduler\MaintenanceScheduleBuilder;
use c975L\ConfigBundle\Scheduler\MaintenanceTask;
use c975L\ConfigBundle\Scheduler\MaintenanceTaskProviderInterface;
use c975L\ConfigBundle\Scheduler\ScheduleSpreader;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Schedule;

class MaintenanceScheduleBuilderTest extends TestCase
{
    private function createProvider(MaintenanceTask ...$tasks): MaintenanceTaskProviderInterface
    {
        return new class (array_values($tasks)) implements MaintenanceTaskProviderInterface {
            public function __construct(private readonly array $tasks)
            {
            }

            public function getMaintenanceTasks(): array
            {
                return $this->tasks;
            }
        };
    }

    private function createBuilder(array $providers, string $siteUrl = 'https://papa-calin.com'): MaintenanceScheduleBuilder
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService
            ->method('get')
            ->willReturn($siteUrl);

        return new MaintenanceScheduleBuilder($providers, new ScheduleSpreader($configService, '/var/www/site'));
    }

    private function expressions(Schedule $schedule): array
    {
        return array_values(array_map(
            static fn ($message): string => (string) $message->getTrigger(),
            $schedule->getRecurringMessages()
        ));
    }

    // Every installed bundle's tasks end up scheduled, which is what lets the app's own MaintenanceSchedule list no command at all
    public function testEveryProvidersTasksAreScheduled(): void
    {
        $builder = $this->createBuilder([
            $this->createProvider(new MaintenanceTask('# */6 * * *', 'c975l:config:backup')),
            $this->createProvider(
                new MaintenanceTask('# #(1-3) * * *', 'c975l:shop:baskets:delete'),
                new MaintenanceTask('# #(1-3) * * *', 'c975l:shop:downloads:delete'),
            ),
        ]);

        $this->assertCount(3, $this->expressions($builder->addTasks(new Schedule())));
    }

    // Two bundles shipping the same command would make Schedule::add() throw at worker start-up, taking every other task down with it
    public function testACommandDeclaredTwiceIsScheduledOnce(): void
    {
        $builder = $this->createBuilder([
            $this->createProvider(new MaintenanceTask('# */6 * * *', 'c975l:config:backup')),
            $this->createProvider(new MaintenanceTask('# #(0-2) * * *', 'c975l:config:backup')),
        ]);

        $this->assertCount(1, $this->expressions($builder->addTasks(new Schedule())));
    }

    // A site that doesn't want a declared command run at all says so, rather than having to stop declaring the bundle
    public function testAnExceptedCommandIsNotScheduled(): void
    {
        $builder = $this->createBuilder([
            $this->createProvider(
                new MaintenanceTask('# */6 * * *', 'c975l:config:backup'),
                new MaintenanceTask('# #(4-7) # * *', 'c975l:health-check:run --frequency=monthly'),
            ),
        ]);

        $schedule = $builder->addTasks(new Schedule(), ['c975l:health-check:run --frequency=monthly']);

        $this->assertSame(['*/6'], array_map(static fn (string $e): string => explode(' ', $e)[1], $this->expressions($schedule)));
    }

    // The tasks go through ScheduleSpreader, so the very same declarations give two sites two different schedules
    public function testTheDeclaredTasksAreSpreadPerInstall(): void
    {
        $tasks = [$this->createProvider(new MaintenanceTask('# */6 * * *', 'c975l:config:backup'))];

        $this->assertNotSame(
            $this->expressions($this->createBuilder($tasks, 'https://papa-calin.com')->addTasks(new Schedule())),
            $this->expressions($this->createBuilder($tasks, 'https://run.as')->addTasks(new Schedule())),
        );
    }

    // The app keeps adding entries of its own afterwards, a command no bundle knows about having nowhere else to go
    public function testTheAppCanStillAddItsOwnEntries(): void
    {
        $builder = $this->createBuilder([$this->createProvider(new MaintenanceTask('# */6 * * *', 'c975l:config:backup'))]);

        $schedule = $builder->addTasks(new Schedule());
        $schedule->add((new ScheduleSpreader($this->createStub(ConfigServiceInterface::class), '/var/www/site'))
            ->spread('0 3 * * *', new RunCommandMessage('app:check-external-links')));

        $this->assertContains('0 3 * * *', $this->expressions($schedule));
    }
}
