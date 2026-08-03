<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use c975L\ConfigBundle\Messenger\SingleEnvelopeReceiver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Mime\Email;

// Reads/purges/retries the Doctrine Messenger failure transport ("messenger_messages" table, queue_name = 'failed'), used by MessengerCleanupCommand, MessengerAlertProvider and MessengerFailedController so the logic to decode a failed Envelope lives in one place
class MessengerFailedMessageService
{
    // Exception message keywords indicating the failure is caused by the recipient's own reputation (blacklisted spammer domain, ...) rather than an issue worth an admin's attention
    private const MINOR_KEYWORDS = ['blacklist', 'blocklist', 'block-listed', 'rbl', 'spam', 'reputation'];

    // What decode() knows about a row whose body it couldn't read back into an Envelope - the same keys describeEnvelope() fills in, so the row is still listed with its id and date
    private const EMPTY_DETAILS = [
        'from' => null,
        'to' => null,
        'subject' => null,
        'exceptionMessage' => null,
        'exceptionClass' => null,
        'exceptionCode' => null,
        'retryCount' => 0,
        'originalTransport' => null,
    ];

    public function __construct(
        private readonly Connection $connection,
        // Wired to "messenger.transport.failed" in services.yaml, the "failed" transport name assumed by the raw SQL below, per the Symfony Messenger recipe default (framework.messenger.failure_transport: failed) - null when the app declares no failure transport, see retry()
        private readonly (ReceiverInterface & ListableReceiverInterface) | null $failureReceiver,
        private readonly MessageBusInterface $messageBus,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    // Returns every failed message, most recent first, decoded into a readable array
    public function findAll(): array
    {
        // The Doctrine transport only creates messenger_messages on its first message, and the dashboard alert queries it on every render - a fresh install has nothing failed to report rather than a broken back-office
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, body, created_at FROM messenger_messages WHERE queue_name = 'failed' ORDER BY created_at DESC"
            );
        } catch (TableNotFoundException) {
            return [];
        }

        return array_map(fn (array $row) => $this->decode($row), $rows);
    }

    // Counts failed messages not classified as minor (spam-related)
    public function countImportant(): int
    {
        // Classifying a message needs its envelope decoded, but the dashboard alert asks for this count on every render: an install with nothing failed - the normal one - answers on a COUNT alone, without fetching and unserializing a single body
        if (0 === $this->countRows()) {
            return 0;
        }

        return count(array_filter($this->findAll(), fn (array $message) => $message['important']));
    }

    // Groups already-decoded messages (see findAll()) by their error message, most frequent first, so the dashboard can offer a "delete all N messages with this error" action
    public function groupByError(array $messages): array
    {
        $groups = [];
        foreach ($messages as $message) {
            $key = $message['exceptionMessage'] ?? '(unknown)';
            $groups[$key]['message'] ??= $key;
            $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
            $groups[$key]['ids'][] = $message['id'];
        }

        usort($groups, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $groups;
    }

    // Removes failed messages older than the given number of days, returns the number removed
    public function purgeOlderThan(int $days): int
    {
        // Compared against a column the transport fills in UTC, so the cutoff is computed in UTC too
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$days} days")->format('Y-m-d H:i:s');

        // Same as findAll(): the nightly cleanup on an install where no message ever failed has nothing to purge, not a reason to report a failed command
        try {
            return $this->connection->executeStatement(
                "DELETE FROM messenger_messages WHERE queue_name = 'failed' AND created_at < ?",
                [$cutoff]
            );
        } catch (TableNotFoundException) {
            return 0;
        }
    }

    // Deletes a single failed message, returns whether a row was actually removed
    public function deleteById(int $id): bool
    {
        return $this->connection->executeStatement(
            "DELETE FROM messenger_messages WHERE id = ? AND queue_name = 'failed'",
            [$id]
        ) > 0;
    }

    // Deletes several failed messages at once (used by the "delete this error group" action)
    public function deleteByIds(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return $this->connection->executeStatement(
            'DELETE FROM messenger_messages WHERE queue_name = \'failed\' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_map('intval', $ids)
        );
    }

    // Replays a single failed message through the real message bus (same handler, same routing as a fresh dispatch), exactly like "messenger:failed:retry" does for one id. On success the message leaves the failure transport; on failure it is re-queued there by Symfony's own retry-strategy listener, and the new error is returned so the caller can display it
    public function retry(int $id): array
    {
        // Replaying needs the transport itself, unlike listing or deleting - an app without a failure transport reports the message as gone rather than fataling
        if (null === $this->failureReceiver) {
            return ['found' => false, 'success' => false, 'error' => null];
        }

        $envelope = $this->failureReceiver->find($id);
        if (null === $envelope) {
            return ['found' => false, 'success' => false, 'error' => null];
        }

        $succeeded = false;
        $newError = null;

        $successListener = function (WorkerMessageHandledEvent $event) use (&$succeeded) {
            $succeeded = true;
        };
        $failedListener = function (WorkerMessageFailedEvent $event) use (&$newError) {
            $throwable = $event->getThrowable();
            if ($throwable instanceof HandlerFailedException) {
                $throwable = $throwable->getPrevious() ?? $throwable;
            }
            $newError = $throwable->getMessage();
        };
        $stopListener = new StopWorkerOnMessageLimitListener(1);

        $this->eventDispatcher->addListener(WorkerMessageHandledEvent::class, $successListener);
        $this->eventDispatcher->addListener(WorkerMessageFailedEvent::class, $failedListener);
        $this->eventDispatcher->addSubscriber($stopListener);

        try {
            $singleReceiver = new SingleEnvelopeReceiver($this->failureReceiver, $envelope);
            $worker = new Worker(['failed' => $singleReceiver], $this->messageBus, $this->eventDispatcher);
            $worker->run(['sleep' => 0]);
        } finally {
            $this->eventDispatcher->removeListener(WorkerMessageHandledEvent::class, $successListener);
            $this->eventDispatcher->removeListener(WorkerMessageFailedEvent::class, $failedListener);
            $this->eventDispatcher->removeSubscriber($stopListener);
        }

        return ['found' => true, 'success' => $succeeded, 'error' => $newError];
    }

    // How many rows sit in the failure queue, whatever they hold - same missing-table tolerance as findAll()
    private function countRows(): int
    {
        try {
            return (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"
            );
        } catch (TableNotFoundException) {
            return 0;
        }
    }

    // Decodes a raw messenger_messages row into a readable array
    private function decode(array $row): array
    {
        [$envelope, $decodeError] = $this->unserializeBody($row['body']);

        $details = match (true) {
            $envelope instanceof Envelope => $this->describeEnvelope($envelope),
            null !== $decodeError => ['exceptionMessage' => 'Unserialize failed: ' . $decodeError] + self::EMPTY_DETAILS,
            default => self::EMPTY_DETAILS,
        };

        return [
            'id' => (int) $row['id'],
            // The Doctrine transport writes created_at from new \DateTimeImmutable('UTC') into a timezone-less DATETIME column, so the stored string has to be read back as UTC - reading it in the app's timezone would shift every date and, worse, make a fresh failure look older than the last-alert marker in MessengerCleanupCommand
            'createdAt' => new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')),
            'from' => $details['from'],
            'to' => $details['to'],
            'subject' => $details['subject'],
            'exceptionMessage' => $details['exceptionMessage'],
            'exceptionClass' => $details['exceptionClass'],
            'exceptionCode' => $details['exceptionCode'],
            'retryCount' => $details['retryCount'],
            'originalTransport' => $details['originalTransport'],
            // Messages we cannot identify as email (to === null) are kept as important by precaution
            'important' => null === $details['to'] || null === $details['exceptionMessage'] || !$this->isMinor($details['exceptionMessage']),
        ];
    }

    // The stored envelope, plus the reason it couldn't be read when that's what happened
    // @return array{0: mixed, 1: ?string}
    private function unserializeBody(string $body): array
    {
        // Symfony's default transport serializer (PhpSerializer) stores serialize($envelope) wrapped in addslashes(), with a base64 fallback for non-UTF8-safe payloads (see PhpSerializer::encode()/decode()); this mirrors that exact pre-processing so the raw SQL body can be unserialized the same way the transport itself would read it
        if (!str_ends_with($body, '}')) {
            $body = base64_decode($body);
        }

        // Captures the reason PHP's unserialize() fails (missing/renamed class, corrupted body, ...) instead of silently swallowing it, so admins see why a row can't be read
        $decodeError = null;
        set_error_handler(function (int $errno, string $errstr) use (&$decodeError) {
            $decodeError = $errstr;

            return true;
        });
        $envelope = unserialize(stripslashes($body));
        restore_error_handler();

        return [$envelope, $decodeError];
    }

    // Everything decode() reports about a readable row - the stamps Messenger itself left on the envelope, plus what the message being an email adds
    private function describeEnvelope(Envelope $envelope): array
    {
        $errorStamp = $envelope->last(ErrorDetailsStamp::class);

        return $this->describeEmail($envelope->getMessage()) + [
            'exceptionMessage' => $errorStamp?->getExceptionMessage(),
            'exceptionClass' => $errorStamp?->getExceptionClass(),
            'exceptionCode' => $errorStamp?->getExceptionCode(),
            'retryCount' => RedeliveryStamp::getRetryCountFromEnvelope($envelope),
            'originalTransport' => $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
        ];
    }

    // from/to/subject when the failed message is an email, all null otherwise - a message of another kind is still listed, just without the fields only an email has
    private function describeEmail(object $message): array
    {
        $email = $message instanceof SendEmailMessage ? $message->getMessage() : null;
        if (!$email instanceof Email) {
            return ['from' => null, 'to' => null, 'subject' => null];
        }

        $fromAddresses = $email->getFrom();
        $toAddresses = $email->getTo();

        return [
            'from' => isset($fromAddresses[0]) ? $fromAddresses[0]->getAddress() : null,
            'to' => isset($toAddresses[0]) ? $toAddresses[0]->getAddress() : null,
            'subject' => $email->getSubject(),
        ];
    }

    // Checks whether the exception message indicates a minor, spam-related failure
    private function isMinor(string $exceptionMessage): bool
    {
        $lower = strtolower($exceptionMessage);
        foreach (self::MINOR_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
