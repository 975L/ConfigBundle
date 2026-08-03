<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\MessengerCleanupCommand;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\MessengerFailedMessageService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class MessengerCleanupCommandTest extends TestCase
{
    private string $projectDir;

    // cleanup() stamps its "last alert" marker in var/, so the command needs a project directory it can actually write to
    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/c975l_messenger_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/var', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir . '/var/MessengerAlertDateTimeFile');
        @rmdir($this->projectDir . '/var');
        @rmdir($this->projectDir);
    }

    private function createParameterBag(): ParameterBagInterface
    {
        $bag = $this->createStub(ParameterBagInterface::class);
        $bag->method('get')->willReturnCallback(
            fn (string $name): string => 'kernel.project_dir' === $name ? $this->projectDir : '',
        );

        return $bag;
    }

    private function createConfigService(array $values): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturnCallback(static fn (string $slug): mixed => $values[$slug] ?? '');

        return $service;
    }

    private function createMessage(bool $important): array
    {
        return [
            'id' => 1,
            'createdAt' => new \DateTimeImmutable(),
            'to' => 'recipient@example.com',
            'subject' => 'Hello',
            'exceptionMessage' => 'Connection refused',
            'important' => $important,
        ];
    }

    private function createService(array $messages, int $purged = 4): MessengerFailedMessageService
    {
        $service = $this->createStub(MessengerFailedMessageService::class);
        $service->method('findAll')->willReturn($messages);
        $service->method('purgeOlderThan')->willReturn($purged);

        return $service;
    }

    private function createCommand(
        MessengerFailedMessageService $service,
        array $configValues,
        ?MailerInterface $mailer = null,
    ): MessengerCleanupCommand {
        return new MessengerCleanupCommand(
            $service,
            $this->createConfigService($configValues),
            $mailer ?? $this->createStub(MailerInterface::class),
            $this->createParameterBag(),
        );
    }

    public function testCleanupPurgesAndReportsWithoutAlertingWhenNothingIsImportant(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $command = $this->createCommand(
            $this->createService([$this->createMessage(false)]),
            ['site-messenger-cleanup-mailto' => 'admin@example.com'],
            $mailer,
        );

        $stats = $command->cleanup();

        $this->assertSame(4, $stats['purged']);
        $this->assertSame(0, $stats['important']);
        $this->assertFalse($stats['alerted']);
    }

    // The mailto being the only switch for the digest, an install that never filled it in still gets its nightly purge
    public function testCleanupStillPurgesWhenNoMailtoIsConfigured(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $stats = $this->createCommand($this->createService([$this->createMessage(true)]), [], $mailer)->cleanup();

        $this->assertSame(4, $stats['purged']);
        $this->assertFalse($stats['alerted']);
    }

    public function testCleanupSendsOneDigestForNewImportantFailures(): void
    {
        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willReturnCallback(
            static function (RawMessage $message) use (&$sent): void {
                $sent = $message;
            },
        );

        $command = $this->createCommand(
            $this->createService([$this->createMessage(true), $this->createMessage(true)]),
            ['site-messenger-cleanup-mailto' => 'admin@example.com', 'email-from' => 'noreply@example.com'],
            $mailer,
        );

        $stats = $command->cleanup();

        $this->assertTrue($stats['alerted']);
        $this->assertSame(2, $stats['newImportant']);
        $this->assertInstanceOf(Email::class, $sent);
        $this->assertSame('noreply@example.com', $sent->getFrom()[0]->getAddress());
        $this->assertSame('admin@example.com', $sent->getTo()[0]->getAddress());
        $this->assertStringContainsString('Connection refused', $sent->getTextBody());
        $this->assertFileExists($this->projectDir . '/var/MessengerAlertDateTimeFile');
    }

    // An empty email-from would make Email::from() throw, and the digest being sent first, that would take the purge down with it
    public function testCleanupFallsBackOnTheRecipientAsSenderWhenEmailFromIsEmpty(): void
    {
        $sent = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $message) use (&$sent): void {
                $sent = $message;
            },
        );

        $command = $this->createCommand(
            $this->createService([$this->createMessage(true)]),
            ['site-messenger-cleanup-mailto' => 'admin@example.com'],
            $mailer,
        );

        $stats = $command->cleanup();

        $this->assertInstanceOf(Email::class, $sent);
        $this->assertSame('admin@example.com', $sent->getFrom()[0]->getAddress());
        $this->assertSame(4, $stats['purged']);
    }

    // The marker file's timestamp is what makes the digest a one-per-batch alert rather than a nightly repeat
    public function testCleanupDoesntAlertTwiceForTheSameFailures(): void
    {
        touch($this->projectDir . '/var/MessengerAlertDateTimeFile', time() + 60);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $stats = $this->createCommand(
            $this->createService([$this->createMessage(true)]),
            ['site-messenger-cleanup-mailto' => 'admin@example.com'],
            $mailer,
        )->cleanup();

        $this->assertSame(1, $stats['important']);
        $this->assertSame(0, $stats['newImportant']);
        $this->assertFalse($stats['alerted']);
    }
}
