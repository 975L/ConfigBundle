<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

// Wraps a receiver to replay a single, already-fetched Envelope through a Worker (used by MessengerFailedMessageService::retry() to retry one failed message without consuming the rest of the failure transport)
class SingleEnvelopeReceiver implements ReceiverInterface
{
    private bool $hasReceived = false;

    public function __construct(
        private readonly ReceiverInterface $receiver,
        private readonly Envelope $envelope,
    ) {
    }

    public function get(): iterable
    {
        if ($this->hasReceived) {
            return [];
        }
        $this->hasReceived = true;

        return [$this->envelope];
    }

    public function ack(Envelope $envelope): void
    {
        $this->receiver->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->receiver->reject($envelope);
    }
}
