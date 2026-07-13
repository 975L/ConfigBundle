<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\DependencyInjection;

use c975L\ConfigBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    // The bundle currently exposes no configuration options: processing an empty
    // set of configs must yield an empty tree, and any unexpected key must be rejected
    public function testProcessingNoConfigurationYieldsAnEmptyArray(): void
    {
        $processor = new Processor();

        $processed = $processor->processConfiguration(new Configuration(), []);

        $this->assertSame([], $processed);
    }

    public function testProcessingAnUnrecognizedKeyThrows(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['unexpected' => 'value']]);
    }
}
