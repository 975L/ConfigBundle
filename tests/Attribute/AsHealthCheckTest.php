<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Attribute;

use c975L\ConfigBundle\Attribute\AsHealthCheck;
use PHPUnit\Framework\TestCase;

class AsHealthCheckTest extends TestCase
{
    // The default is what makes the attribute optional: a provider only carries it to say it is not weekly
    public function testFrequencyDefaultsToWeekly(): void
    {
        $this->assertSame(AsHealthCheck::FREQUENCY_WEEKLY, (new AsHealthCheck())->frequency);
    }

    public function testFrequencyIsTheDeclaredOne(): void
    {
        $this->assertSame('monthly', (new AsHealthCheck(AsHealthCheck::FREQUENCY_MONTHLY))->frequency);
    }

    // A cadence no cron entry asks for would silently never run, which is the failure this whole mechanism replaces - so it is refused where it is written, not where it is read
    public function testAnUnknownFrequencyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid health check frequency "daily"');

        new AsHealthCheck('daily');
    }
}
