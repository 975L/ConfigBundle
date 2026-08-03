<?php

namespace App\Tests\Scheduler;

use App\Scheduler\MaintenanceSchedule;
use c975L\ConfigBundle\Scheduler\MaintenanceScheduleBuilder;
use c975L\ConfigBundle\Scheduler\MaintenanceTask;
use c975L\ConfigBundle\Scheduler\MaintenanceTaskProviderInterface;
use c975L\ConfigBundle\Scheduler\ScheduleSpreader;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Cache\CacheInterface;

class MaintenanceScheduleTest extends TestCase
{
    // What the app is still responsible for: a stateful schedule carrying whatever the installed bundles declared. Which commands those are is each bundle's own business, and asserting them here would break this suite on every bundle upgrade - the very coupling this schedule no longer has
    public function testGetScheduleIsStatefulAndCarriesTheDeclaredTasks(): void
    {
        $cache = $this->createStub(CacheInterface::class);

        $schedule = (new MaintenanceSchedule($this->createBuilder(), $cache))->getSchedule();

        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertSame($cache, $schedule->getState());

        $recurringMessages = array_values($schedule->getRecurringMessages());
        $this->assertCount(1, $recurringMessages);

        $recurringMessage = $recurringMessages[0];
        $context = new MessageContext('site', $recurringMessage->getId(), $recurringMessage->getTrigger(), new \DateTimeImmutable());
        $messages = iterator_to_array($recurringMessage->getMessages($context));
        $this->assertInstanceOf(RunCommandMessage::class, $messages[0]);
        $this->assertSame('c975l:config:backup', $messages[0]->input);
    }

    // The declared cadence is drawn per site rather than fixed, so this site's own minute is whatever ScheduleSpreader gave it - only the shape can be asserted
    public function testTheCadenceIsSpreadOverThisSitesOwnMinute(): void
    {
        $schedule = (new MaintenanceSchedule($this->createBuilder(), $this->createStub(CacheInterface::class)))->getSchedule();

        $trigger = (string) array_values($schedule->getRecurringMessages())[0]->getTrigger();

        $this->assertMatchesRegularExpression('/^\d{1,2} \*\/6 \* \* \*$/', $trigger);
    }

    // A builder fed with one task of its own: this test is about the schedule, not about what the installed bundles happen to declare
    private function createBuilder(): MaintenanceScheduleBuilder
    {
        $provider = new class implements MaintenanceTaskProviderInterface {
            public function getMaintenanceTasks(): array
            {
                return [new MaintenanceTask('# */6 * * *', 'c975l:config:backup')];
            }
        };

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService
            ->method('get')
            ->willReturn('https://example.com');

        return new MaintenanceScheduleBuilder([$provider], new ScheduleSpreader($configService, '/var/www/site'));
    }
}
