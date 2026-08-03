<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\MessengerFailedMessageService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Mime\Email;

class MessengerFailedMessageServiceTest extends TestCase
{
    private function createService(Connection $connection): MessengerFailedMessageService
    {
        return new MessengerFailedMessageService(
            $connection,
            $this->createStub(ListableReceiverInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(EventDispatcherInterface::class),
        );
    }

    // Mirrors Symfony's default PhpSerializer::encode(): serialize($envelope) wrapped in addslashes(), exactly what ends up stored in messenger_messages.body
    private function encode(Envelope $envelope): string
    {
        return addslashes(serialize($envelope));
    }

    private function connectionReturning(array $rows): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);
        // countImportant() asks for the row count before decoding anything (see countRows())
        $connection->method('fetchOne')->willReturn(count($rows));

        return $connection;
    }

    public function testFindAllDecodesAFailedEmailEnvelope(): void
    {
        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Hello');

        $envelope = new Envelope(new SendEmailMessage($email), [
            new ErrorDetailsStamp(\RuntimeException::class, 500, 'Connection refused'),
            new RedeliveryStamp(2),
            new SentToFailureTransportStamp('async'),
        ]);

        $row = ['id' => '1', 'body' => $this->encode($envelope), 'created_at' => '2026-07-15 10:00:00'];
        $service = $this->createService($this->connectionReturning([$row]));

        $messages = $service->findAll();

        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame(1, $message['id']);
        $this->assertSame('sender@example.com', $message['from']);
        $this->assertSame('recipient@example.com', $message['to']);
        $this->assertSame('Hello', $message['subject']);
        $this->assertSame('Connection refused', $message['exceptionMessage']);
        $this->assertSame(\RuntimeException::class, $message['exceptionClass']);
        $this->assertSame(500, $message['exceptionCode']);
        $this->assertSame(2, $message['retryCount']);
        $this->assertSame('async', $message['originalTransport']);
        $this->assertTrue($message['important']);
    }

    // The transport stores created_at in UTC, so the decoded date has to point at the same instant whatever the app's timezone - a date read as local time would make a fresh failure look older than it is to MessengerCleanupCommand's last-alert marker
    public function testFindAllReadsTheStoredDateAsUtc(): void
    {
        $row = ['id' => '3', 'body' => addslashes('unreadable'), 'created_at' => '2026-07-15 10:00:00'];
        $service = $this->createService($this->connectionReturning([$row]));

        $messages = $service->findAll();

        $this->assertSame(
            (new \DateTimeImmutable('2026-07-15 10:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
            $messages[0]['createdAt']->getTimestamp()
        );
    }

    // A body that fails to unserialize (renamed class, corruption...) doesn't crash the listing - the failure reason itself becomes the readable "exceptionMessage" instead
    public function testFindAllReportsAnUnserializableBodyInsteadOfCrashing(): void
    {
        $row = ['id' => '2', 'body' => addslashes('not a valid serialized payload'), 'created_at' => '2026-07-15 10:00:00'];
        $service = $this->createService($this->connectionReturning([$row]));

        $messages = $service->findAll();

        $this->assertCount(1, $messages);
        $this->assertNull($messages[0]['to']);
        $this->assertStringStartsWith('Unserialize failed:', $messages[0]['exceptionMessage']);
        // Unidentifiable messages (no "to") are kept as important by precaution
        $this->assertTrue($messages[0]['important']);
    }

    // A blacklist/reputation-related error on an identified email is classified as minor, so it doesn't count towards countImportant()
    public function testFindAllAndPurgeReturnEmptyWhenTheTableDoesNotExistYet(): void
    {
        $notFound = new TableNotFoundException($this->createStub(DriverException::class), null);
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willThrowException($notFound);
        $connection->method('fetchOne')->willThrowException($notFound);
        $connection->method('executeStatement')->willThrowException($notFound);
        $service = $this->createService($connection);

        $this->assertSame([], $service->findAll());
        $this->assertSame(0, $service->countImportant());
        $this->assertSame(0, $service->purgeOlderThan(30));
    }

    // An app declaring no failure transport gets a null receiver (see services.yaml), which only costs it the ability to replay a message
    public function testRetryReportsNotFoundWithoutAFailureTransport(): void
    {
        $service = new MessengerFailedMessageService(
            $this->connectionReturning([]),
            null,
            $this->createStub(MessageBusInterface::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        $this->assertSame(['found' => false, 'success' => false, 'error' => null], $service->retry(1));
    }

    public function testCountImportantExcludesBlacklistFailures(): void
    {
        $email = (new Email())->from('sender@example.com')->to('recipient@example.com')->subject('Hi');
        $envelope = new Envelope(new SendEmailMessage($email), [
            new ErrorDetailsStamp(\RuntimeException::class, 550, 'Recipient domain is blacklisted'),
        ]);

        $row = ['id' => '3', 'body' => $this->encode($envelope), 'created_at' => '2026-07-15 10:00:00'];
        $service = $this->createService($this->connectionReturning([$row]));

        $this->assertFalse($service->findAll()[0]['important']);
        $this->assertSame(0, $service->countImportant());
    }
}
