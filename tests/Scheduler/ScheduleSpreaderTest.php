<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Scheduler;

use c975L\ConfigBundle\Scheduler\ScheduleSpreader;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;

class ScheduleSpreaderTest extends TestCase
{
    private function createSpreader(?string $siteUrl, string $projectDir = '/var/www/site'): ScheduleSpreader
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService
            ->method('get')
            ->willReturn($siteUrl);

        return new ScheduleSpreader($configService, $projectDir);
    }

    private function resolve(ScheduleSpreader $spreader, string $expression, string $command = 'c975l:config:backup'): string
    {
        return (string) $spreader->spread($expression, new RunCommandMessage($command))->getTrigger();
    }

    // The whole point: the same command, scheduled from the same scaffold, must not land on the same minute on two installs
    public function testTwoInstallsGetDifferentExpressions(): void
    {
        $first = $this->resolve($this->createSpreader('https://papa-calin.com'), '# */6 * * *');
        $second = $this->resolve($this->createSpreader('https://run.as'), '# */6 * * *');

        $this->assertNotSame($first, $second);
    }

    // Deterministic: a worker restart, or a redeploy, must not move the schedule around
    public function testSameInstallAlwaysGetsTheSameExpression(): void
    {
        $expected = $this->resolve($this->createSpreader('https://papa-calin.com'), '# #(0-2) * * *');

        $this->assertSame($expected, $this->resolve($this->createSpreader('https://papa-calin.com'), '# #(0-2) * * *'));
    }

    // Only the placeholders are drawn, the rest of the expression is what the app asked for
    public function testOnlyPlaceholdersAreReplaced(): void
    {
        $resolved = $this->resolve($this->createSpreader('https://papa-calin.com'), '# #(0-2) * * *');

        $this->assertMatchesRegularExpression('/^\d{1,2} [0-2] \* \* \*$/', $resolved);
    }

    // An install that wants a fixed hour keeps writing a plain expression, and nothing is spread
    public function testExpressionWithoutPlaceholderIsUntouched(): void
    {
        $this->assertSame('0 3 * * *', $this->resolve($this->createSpreader('https://papa-calin.com'), '0 3 * * *'));
    }

    // Two commands sharing one expression are spread apart too, which matters even to an install that is alone on its server
    public function testTwoCommandsOfTheSameInstallAreSpread(): void
    {
        $spreader = $this->createSpreader('https://papa-calin.com');

        $this->assertNotSame(
            $this->resolve($spreader, '# #(0-2) * * *', 'c975l:config:backup'),
            $this->resolve($spreader, '# #(0-2) * * *', 'c975l:sitemaps:create'),
        );
    }

    // The signature takes any object, and an app scheduling a message of its own must not bring the worker down on start-up
    public function testMessageThatIsNotStringableIsSpreadOnItsClass(): void
    {
        $spreader = $this->createSpreader('https://papa-calin.com');

        $resolved = (string) $spreader->spread('# #(0-2) * * *', new \stdClass())->getTrigger();

        $this->assertMatchesRegularExpression('/^\d{1,2} [0-2] \* \* \*$/', $resolved);
    }

    // A site whose url isn't configured yet still gets spread, on its install path rather than on a value shared by every fresh install
    public function testFallsBackOnProjectDirWhenSiteUrlIsEmpty(): void
    {
        $first = $this->resolve($this->createSpreader(null, '/var/www/first'), '# */6 * * *');
        $second = $this->resolve($this->createSpreader('', '/var/www/second'), '# */6 * * *');

        $this->assertNotSame($first, $second);
    }
}
