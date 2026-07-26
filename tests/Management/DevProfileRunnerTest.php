<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\DevProfileAnalyzer;
use c975L\ConfigBundle\Management\DevProfileCollector;
use c975L\ConfigBundle\Management\DevProfilePathProviderInterface;
use c975L\ConfigBundle\Management\DevProfileRunner;
use PHPUnit\Framework\TestCase;

class DevProfileRunnerTest extends TestCase
{
    private function createProvider(array $paths): DevProfilePathProviderInterface
    {
        $provider = $this->createStub(DevProfilePathProviderInterface::class);
        $provider->method('getPaths')->willReturn($paths);

        return $provider;
    }

    // Records every path collect() was called with, and answers metrics carrying that path back
    private function createCollector(array &$calls): DevProfileCollector
    {
        $collector = $this->createStub(DevProfileCollector::class);
        $collector->method('collect')->willReturnCallback(function (string $path, ?string $label = null) use (&$calls): array {
            $calls[] = $path;

            return ['path' => $path, 'label' => $label, 'statusCode' => 200, 'error' => null, 'queries' => 1];
        });

        return $collector;
    }

    public function testRunProfilesEveryDeclaredPathAndAnalyzesEachOne(): void
    {
        $calls = [];
        $analyzer = $this->createStub(DevProfileAnalyzer::class);
        $analyzer->method('analyze')->willReturn([['level' => DevProfileAnalyzer::LEVEL_WARNING, 'area' => 'Twig', 'message' => 'trop de templates']]);

        $runner = new DevProfileRunner(
            [$this->createProvider([['path' => '/', 'label' => 'Accueil'], ['path' => '/pages/contact', 'label' => 'Contact']])],
            $this->createCollector($calls),
            $analyzer
        );
        $report = $runner->run();

        $this->assertCount(2, $report);
        $this->assertSame('/', $report[0]['path']);
        $this->assertSame('Accueil', $report[0]['label']);
        $this->assertSame(['level' => DevProfileAnalyzer::LEVEL_WARNING, 'area' => 'Twig', 'message' => 'trop de templates'], $report[0]['issues'][0]);
        $this->assertSame('/pages/contact', $report[1]['path']);
    }

    // The first path is profiled twice and its first result dropped: the kernel stays booted, so it would otherwise carry every warm-up cost none of the following paths show
    public function testRunProfilesTheFirstPathOnceMoreAsAWarmUp(): void
    {
        $calls = [];
        $runner = new DevProfileRunner(
            [$this->createProvider([['path' => '/', 'label' => 'Accueil'], ['path' => '/pages/contact', 'label' => 'Contact']])],
            $this->createCollector($calls),
            new DevProfileAnalyzer()
        );
        $report = $runner->run();

        $this->assertSame(['/', '/', '/pages/contact'], $calls);
        $this->assertCount(2, $report);
    }

    public function testRunMergesEveryProviderAndProfilesADuplicatedPathOnlyOnce(): void
    {
        $calls = [];
        $runner = new DevProfileRunner(
            [
                $this->createProvider([['path' => '/', 'label' => 'Accueil'], ['path' => '/shop', 'label' => 'Boutique']]),
                $this->createProvider([['path' => '/shop', 'label' => 'Boutique (autre bundle)'], ['path' => '/book', 'label' => 'Livres']]),
            ],
            $this->createCollector($calls),
            new DevProfileAnalyzer()
        );
        $report = $runner->run();

        $this->assertSame(['/', '/shop', '/book'], array_column($report, 'path'));
        $this->assertSame('Boutique', $report[1]['label']);
    }

    public function testRunRestrictsToTheGivenPathsAndAcceptsOneNoProviderDeclares(): void
    {
        $calls = [];
        $runner = new DevProfileRunner(
            [$this->createProvider([['path' => '/', 'label' => 'Accueil'], ['path' => '/pages/contact', 'label' => 'Contact']])],
            $this->createCollector($calls),
            new DevProfileAnalyzer()
        );
        $report = $runner->run(['/pages/contact', '/admin']);

        $this->assertSame(['/pages/contact', '/admin'], array_column($report, 'path'));
        $this->assertSame('Contact', $report[0]['label']);
        $this->assertNull($report[1]['label']);
    }

    public function testRunReturnsNothingAndProfilesNothingWithoutAnyProvider(): void
    {
        $calls = [];
        $runner = new DevProfileRunner([], $this->createCollector($calls), new DevProfileAnalyzer());

        $this->assertSame([], $runner->run());
        $this->assertSame([], $calls);
    }

    public function testRunIgnoresAProviderEntryWithoutAPath(): void
    {
        $calls = [];
        $runner = new DevProfileRunner(
            [$this->createProvider([['label' => 'Sans chemin'], ['path' => '/', 'label' => 'Accueil']])],
            $this->createCollector($calls),
            new DevProfileAnalyzer()
        );

        $this->assertSame(['/'], array_column($runner->run(), 'path'));
    }
}
