<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\DevProfileCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Contracts\Service\ResetInterface;

class DevProfileCollectorTest extends TestCase
{
    private function createKernel(Response $response, ?Request &$handled = null): KernelInterface
    {
        $kernel = $this->createStub(DevProfileCollectorTestKernelFixture::class);
        $kernel->method('handle')->willReturnCallback(function (Request $request) use ($response, &$handled): Response {
            $handled = $request;

            return $response;
        });

        return $kernel;
    }

    // A profiler answering the given profile, or none at all when null - the very sequence KernelBrowser::getProfile() goes through
    private function createProfiler(?Profile $profile): Profiler
    {
        $profiler = $this->createStub(Profiler::class);
        $profiler->method('loadProfileFromResponse')->willReturn($profile);

        return $profiler;
    }

    private function createProfile(array $collectors): Profile
    {
        $profile = new Profile('token');
        foreach ($collectors as $collector) {
            $profile->addCollector($collector);
        }

        return $profile;
    }

    // Every collector the dev toolbar shows, each filled with what a real page would report
    private function createFullProfile(): Profile
    {
        return $this->createProfile([
            new DevProfileCollectorTestCollectorFixture('db', [
                'queryCount' => 47,
                'time' => 31.24,
                'groupedQueries' => [['default' => ['sql' => 'SELECT t0.id FROM site_block t0 WHERE t0.page_id = ?', 'count' => 32]]],
            ]),
            new DevProfileCollectorTestCollectorFixture('logger', [
                'deprecations' => 2,
                'errors' => 1,
                'processedLogs' => [
                    ['type' => 'deprecation', 'message' => 'Since symfony/framework-bundle 7.3: not doing that anymore'],
                    ['type' => 'deprecation', 'message' => 'Since symfony/framework-bundle 7.3: not doing that anymore'],
                    ['type' => 'error', 'message' => 'Something failed'],
                ],
            ]),
            new DevProfileCollectorTestCollectorFixture('translation', [
                'countMissings' => 1,
                'countFallbacks' => 3,
                'messages' => [
                    ['id' => 'label.legal', 'domain' => 'site', 'state' => 1],
                    ['id' => 'label.home', 'domain' => 'site', 'state' => 0],
                ],
            ]),
            new DevProfileCollectorTestCollectorFixture('twig', ['templateCount' => 68, 'time' => 44.11]),
            new DevProfileCollectorTestCollectorFixture('cache', ['totals' => ['hits' => 12, 'misses' => 28, 'writes' => 4]]),
            new DevProfileCollectorTestCollectorFixture('http_client', ['requestCount' => 2]),
            new DevProfileCollectorTestCollectorFixture('time', ['duration' => 240.37]),
            new DevProfileCollectorTestCollectorFixture('memory', ['memory' => 14889779]),
        ]);
    }

    private function createCollector(?Profiler $profiler, ?Response $response = null, ?Request &$handled = null, ?ResetInterface $servicesResetter = null): DevProfileCollector
    {
        return new DevProfileCollector(
            $this->createKernel($response ?? new Response('', 200), $handled),
            $profiler,
            $servicesResetter ?? $this->createStub(ResetInterface::class)
        );
    }

    // A dev environment set up without symfony/profiler-pack has nothing to measure, and saying so beats a page reported as clean
    public function testCollectReportsAnErrorWithoutAProfiler(): void
    {
        $metrics = $this->createCollector(null)->collect('/', 'Accueil');

        $this->assertStringContainsString('profiler-pack', $metrics['error']);
        $this->assertSame('/', $metrics['path']);
        $this->assertSame('Accueil', $metrics['label']);
        $this->assertSame(0, $metrics['statusCode']);
        $this->assertSame(0, $metrics['queries']);
    }

    public function testCollectReadsEveryCollectorOfTheProfile(): void
    {
        $metrics = $this->createCollector($this->createProfiler($this->createFullProfile()))->collect('/', 'Accueil');

        $this->assertNull($metrics['error']);
        $this->assertSame(200, $metrics['statusCode']);
        $this->assertSame(47, $metrics['queries']);
        $this->assertSame(31.2, $metrics['queryTime']);
        $this->assertSame(2, $metrics['deprecations']);
        $this->assertSame(1, $metrics['logErrors']);
        $this->assertSame(1, $metrics['missingTranslations']);
        $this->assertSame(3, $metrics['fallbackTranslations']);
        $this->assertSame(68, $metrics['templates']);
        $this->assertSame(44.1, $metrics['twigTime']);
        $this->assertSame(12, $metrics['cacheHits']);
        $this->assertSame(28, $metrics['cacheMisses']);
        $this->assertSame(4, $metrics['cacheWrites']);
        $this->assertSame(2, $metrics['httpRequests']);
        $this->assertSame(240.4, $metrics['duration']);
        $this->assertSame(14889779, $metrics['memory']);
    }

    // The same sql fired again and again is an n+1 whatever the total, counted as the queries that didn't need to be there
    public function testCollectCountsDuplicateQueriesAndKeepsTheWorstOffender(): void
    {
        $metrics = $this->createCollector($this->createProfiler($this->createProfile([
            new DevProfileCollectorTestCollectorFixture('db', ['groupedQueries' => [
                ['default' => ['sql' => 'SELECT t0.id FROM site_block t0', 'count' => 32]],
                ['default' => ['sql' => 'SELECT t0.id FROM site_page t0', 'count' => 3]],
                ['default' => ['sql' => 'SELECT t0.id FROM config t0', 'count' => 1]],
            ]]),
        ])))->collect('/');

        $this->assertSame(33, $metrics['duplicateQueries']);
        $this->assertSame(32, $metrics['worstDuplicateQuery']['count']);
        $this->assertSame('SELECT t0.id FROM site_block t0', $metrics['worstDuplicateQuery']['sql']);
    }

    public function testCollectKeepsNoDuplicateQueryWhenEverySqlRanOnce(): void
    {
        $metrics = $this->createCollector($this->createProfiler($this->createProfile([
            new DevProfileCollectorTestCollectorFixture('db', ['groupedQueries' => [[['sql' => 'SELECT 1', 'count' => 1]]]]),
        ])))->collect('/');

        $this->assertSame(0, $metrics['duplicateQueries']);
        $this->assertNull($metrics['worstDuplicateQuery']);
    }

    // The same deprecation is usually triggered on every call site of the deprecated code
    public function testCollectKeepsOnlyDistinctDeprecationMessages(): void
    {
        $metrics = $this->createCollector($this->createProfiler($this->createFullProfile()))->collect('/');

        $this->assertSame(['Since symfony/framework-bundle 7.3: not doing that anymore'], $metrics['deprecationMessages']);
    }

    public function testCollectListsOnlyTheKeysWithNoTranslationAtAll(): void
    {
        $metrics = $this->createCollector($this->createProfiler($this->createFullProfile()))->collect('/');

        $this->assertSame(['label.legal (site)'], $metrics['missingTranslationKeys']);
    }

    // getMessages() comes back as a VarDumper Data once the profile has been through storage
    public function testCollectUnwrapsTheTranslationMessagesComingBackAsVarDumperData(): void
    {
        $metrics = $this->createCollector($this->createProfiler($this->createProfile([
            new DevProfileCollectorTestCollectorFixture('translation', [
                'messages' => (new VarCloner())->cloneVar([['id' => 'label.legal', 'domain' => 'site', 'state' => 1]]),
            ]),
        ])))->collect('/');

        $this->assertSame(['label.legal (site)'], $metrics['missingTranslationKeys']);
    }

    // An app without doctrine, twig or an http client has no such collector, and every number it would have filled simply stays at 0
    public function testCollectKeepsTheDefaultsForACollectorTheProfileDoesNotHave(): void
    {
        $metrics = $this->createCollector($this->createProfiler($this->createProfile([])))->collect('/');

        $this->assertSame(200, $metrics['statusCode']);
        $this->assertSame(0, $metrics['queries']);
        $this->assertSame(0, $metrics['templates']);
        $this->assertSame(0, $metrics['httpRequests']);
        $this->assertSame([], $metrics['deprecationMessages']);
    }

    // A version of an optional package that renamed an accessor must not fatal the whole run
    public function testCollectKeepsTheDefaultForACollectorMissingTheAccessor(): void
    {
        $metrics = $this->createCollector($this->createProfiler($this->createProfile([
            new DevProfileCollectorTestBareCollectorFixture('db'),
        ])))->collect('/');

        $this->assertSame(0, $metrics['queries']);
        $this->assertSame(0, $metrics['duplicateQueries']);
    }

    // Only the kernel failing to answer at all gets here, handle() turning the app's own exceptions into a 500 response
    public function testCollectReportsAThrowingKernelAsItsOwnErrorAndStillResets(): void
    {
        $kernel = $this->createStub(DevProfileCollectorTestKernelFixture::class);
        $kernel->method('handle')->willThrowException(new \RuntimeException('Service introuvable'));

        $servicesResetter = $this->createMock(ResetInterface::class);
        $servicesResetter->expects($this->once())->method('reset');

        $collector = new DevProfileCollector($kernel, $this->createProfiler(null), $servicesResetter);
        $metrics = $collector->collect('/', 'Accueil');

        $this->assertSame('Service introuvable', $metrics['error']);
        $this->assertSame(0, $metrics['statusCode']);
    }

    // Without it every path would be reported carrying the previous ones' numbers, the collectors accumulating for the whole process
    public function testCollectResetsTheServicesAfterAProfiledPath(): void
    {
        $servicesResetter = $this->createMock(ResetInterface::class);
        $servicesResetter->expects($this->once())->method('reset');

        $this->createCollector($this->createProfiler($this->createFullProfile()), null, $handled, $servicesResetter)->collect('/');
    }

    // The status code alone is still worth reporting when nothing was stored
    public function testCollectStillReportsTheStatusCodeWithoutAProfile(): void
    {
        $metrics = $this->createCollector($this->createProfiler(null), new Response('', 404))->collect('/');

        $this->assertSame(404, $metrics['statusCode']);
        $this->assertNull($metrics['error']);
        $this->assertSame(0, $metrics['queries']);
    }

    // The path is handed straight to the kernel, no HTTP request and no host involved - https so a route requiring that scheme doesn't answer a redirect instead of the page
    public function testCollectHandsThePathToTheKernelAsALocalHttpsRequest(): void
    {
        $handled = null;
        $this->createCollector($this->createProfiler(null), null, $handled)->collect('/pages/contact');

        $this->assertSame('/pages/contact', $handled->getPathInfo());
        $this->assertSame('https', $handled->getScheme());
        $this->assertSame('GET', $handled->getMethod());
    }

    // The profile is only written to storage on terminate, which is what loadProfileFromResponse() reads it back from
    public function testCollectTerminatesTheKernel(): void
    {
        $response = new Response('', 200);
        $kernel = $this->createMock(DevProfileCollectorTestKernelFixture::class);
        $kernel->method('handle')->willReturn($response);
        $kernel->expects($this->once())->method('terminate')->with($this->isInstanceOf(Request::class), $response);

        (new DevProfileCollector($kernel, $this->createProfiler(null), $this->createStub(ResetInterface::class)))->collect('/');
    }
}

// Own PSR-4 files (see c975LConfigBundleTest for why): the kernel handed to DevProfileCollector is the app's own, which is terminable - KernelInterface alone isn't
interface DevProfileCollectorTestKernelFixture extends KernelInterface, TerminableInterface
{
}

// Answers every accessor DevProfileCollector reads, whatever the collector's name - it only ever asks the ones belonging to that name
class DevProfileCollectorTestCollectorFixture implements DataCollectorInterface
{
    public function __construct(
        private readonly string $name,
        private readonly array $values = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function reset(): void
    {
    }

    public function getQueryCount(): int
    {
        return $this->values['queryCount'] ?? 0;
    }

    public function getGroupedQueries(): array
    {
        return $this->values['groupedQueries'] ?? [];
    }

    // Shared by the db and twig collectors, each reading its own value
    public function getTime(): float
    {
        return $this->values['time'] ?? 0.0;
    }

    public function countDeprecations(): int
    {
        return $this->values['deprecations'] ?? 0;
    }

    public function countErrors(): int
    {
        return $this->values['errors'] ?? 0;
    }

    public function getProcessedLogs(): array
    {
        return $this->values['processedLogs'] ?? [];
    }

    public function getCountMissings(): int
    {
        return $this->values['countMissings'] ?? 0;
    }

    public function getCountFallbacks(): int
    {
        return $this->values['countFallbacks'] ?? 0;
    }

    public function getMessages(): mixed
    {
        return $this->values['messages'] ?? [];
    }

    public function getTemplateCount(): int
    {
        return $this->values['templateCount'] ?? 0;
    }

    public function getTotals(): array
    {
        return $this->values['totals'] ?? [];
    }

    public function getRequestCount(): int
    {
        return $this->values['requestCount'] ?? 0;
    }

    public function getDuration(): float
    {
        return $this->values['duration'] ?? 0.0;
    }

    public function getMemory(): int
    {
        return $this->values['memory'] ?? 0;
    }
}

// A collector answering to a name DevProfileCollector reads, but carrying none of the accessors it expects
class DevProfileCollectorTestBareCollectorFixture implements DataCollectorInterface
{
    public function __construct(
        private readonly string $name,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function reset(): void
    {
    }
}
