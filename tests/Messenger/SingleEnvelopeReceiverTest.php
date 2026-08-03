<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Messenger;

use c975L\ConfigBundle\Messenger\SingleEnvelopeReceiver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

class SingleEnvelopeReceiverTest extends TestCase
{
    private function createEnvelope(): Envelope
    {
        return new Envelope(new \stdClass());
    }

    public function testGetReturnsTheWrappedEnvelopeOnce(): void
    {
        $envelope = $this->createEnvelope();
        $receiver = new SingleEnvelopeReceiver($this->createStub(ReceiverInterface::class), $envelope);

        $this->assertSame([$envelope], $receiver->get());
    }

    // What keeps a "retry this one message" run from turning into a worker consuming the whole failure transport: the Worker loops until it gets nothing back
    public function testGetReturnsNothingOnEverySubsequentCall(): void
    {
        $receiver = new SingleEnvelopeReceiver($this->createStub(ReceiverInterface::class), $this->createEnvelope());
        $receiver->get();

        $this->assertSame([], $receiver->get());
        $this->assertSame([], $receiver->get());
    }

    // Acking and rejecting go to the real transport, that being where the message actually lives
    public function testAckAndRejectAreDelegatedToTheWrappedReceiver(): void
    {
        $envelope = $this->createEnvelope();
        $wrapped = $this->createMock(ReceiverInterface::class);
        $wrapped->expects($this->once())->method('ack')->with($envelope);
        $wrapped->expects($this->once())->method('reject')->with($envelope);

        $receiver = new SingleEnvelopeReceiver($wrapped, $envelope);
        $receiver->ack($envelope);
        $receiver->reject($envelope);
    }
}
