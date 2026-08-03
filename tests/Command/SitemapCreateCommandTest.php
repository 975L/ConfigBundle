<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\SitemapCreateCommand;
use c975L\ConfigBundle\Management\SitemapWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SitemapCreateCommandTest extends TestCase
{
    private function createCommandTester(array $names): CommandTester
    {
        $sitemapWriter = $this->createMock(SitemapWriter::class);
        $sitemapWriter->expects($this->once())->method('write')->willReturn($names);

        return new CommandTester(new SitemapCreateCommand($sitemapWriter));
    }

    // The command is only the console entry point to SitemapWriter, so it must report the names the writer actually wrote
    public function testExecuteWritesSitemapsAndListsThem(): void
    {
        $commandTester = $this->createCommandTester(['site', 'book']);

        $this->assertSame(Command::SUCCESS, $commandTester->execute([]));
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('site', $display);
        $this->assertStringContainsString('book', $display);
    }

    // No provider having anything to declare is a valid state (a brand new site), so it's a warning and not a failure
    public function testExecuteWarnsWhenNothingWasWritten(): void
    {
        $commandTester = $this->createCommandTester([]);

        $this->assertSame(Command::SUCCESS, $commandTester->execute([]));
        $this->assertStringContainsString('No SitemapProvider', $commandTester->getDisplay());
    }
}
