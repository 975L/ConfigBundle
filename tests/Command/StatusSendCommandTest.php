<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\StatusSendCommand;
use c975L\ConfigBundle\Management\StatusReportBuilder;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class StatusSendCommandTest extends TestCase
{
    private const REPORT = ['version' => 1, 'site' => 'https://papa-calin.com'];

    private function createConfigService(?string $url, ?string $key): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService
            ->method('get')
            ->willReturnMap([
                ['site-status-url', $url],
                ['site-status-key', $key],
            ]);

        return $configService;
    }

    private function createTester(?string $url, ?string $key, HttpClientInterface $httpClient): CommandTester
    {
        $statusReportBuilder = $this->createStub(StatusReportBuilder::class);
        $statusReportBuilder->method('build')->willReturn(self::REPORT);

        return new CommandTester(new StatusSendCommand($statusReportBuilder, $this->createConfigService($url, $key), $httpClient));
    }

    private function createHttpClient(int $statusCode = 200): HttpClientInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return $httpClient;
    }

    // The only way to see exactly what would leave the site, and it must need no url, no key and no network
    public function testDumpPrintsTheReportWithoutSendingAnything(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $tester = $this->createTester(null, null, $httpClient);
        $tester->execute(['--dump' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame(self::REPORT, json_decode($tester->getDisplay(), true));
    }

    // Nothing configured is the default state and a legitimate one: installing the bundle must never make a site talk to a third party
    public function testNothingIsSentWithoutAConfiguredUrl(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $tester = $this->createTester('', 'a-key', $httpClient);
        $tester->execute([]);

        // Success rather than failure, so a scheduled entry on a site that opted out doesn't report an error every week
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testAnUrlMadeOfSpacesCountsAsNoUrl(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $tester = $this->createTester('   ', 'a-key', $httpClient);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // A half-finished setup: sending unauthenticated would either be rejected or, worse, accepted by a receiver that doesn't check
    public function testAnUrlWithoutAKeyIsRefusedRatherThanSentUnauthenticated(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $tester = $this->createTester('https://console.example.com/status', '', $httpClient);
        $tester->execute([]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    // The key travels in a header, never in the query string: an url ends up in the receiver's access log and in the Referer of anything it serves
    public function testTheReportIsPostedWithTheKeyInItsHeader(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://console.example.com/status',
                $this->callback(function (array $options): bool {
                    $this->assertSame(['X-Status-Key' => 'a-key'], $options['headers']);
                    $this->assertSame(self::REPORT, $options['json']);

                    return true;
                }),
            )
            ->willReturn($response);

        $tester = $this->createTester('https://console.example.com/status', 'a-key', $httpClient);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testTheUrlAndTheKeyAreTrimmed(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://console.example.com/status',
                $this->callback(fn (array $options): bool => ['X-Status-Key' => 'a-key'] === $options['headers']),
            )
            ->willReturn($response);

        $tester = $this->createTester(' https://console.example.com/status ', ' a-key ', $httpClient);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testARefusingReceiverIsReportedAsAFailure(): void
    {
        $tester = $this->createTester('https://console.example.com/status', 'a-key', $this->createHttpClient(403));
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('403', $tester->getDisplay());
    }

    // An unreachable receiver must not let the exception out of the command, a scheduled run reading its exit code
    public function testAnUnreachableReceiverIsReportedAsAFailure(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new \RuntimeException('Connection refused'));

        $tester = $this->createTester('https://console.example.com/status', 'a-key', $httpClient);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Connection refused', $tester->getDisplay());
    }
}
