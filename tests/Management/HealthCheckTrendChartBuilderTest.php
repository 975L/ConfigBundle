<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckTrendChartBuilder;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class HealthCheckTrendChartBuilderTest extends TestCase
{
    private function createRepository(array $trend): HealthCheckResultRepository
    {
        $repository = $this->createStub(HealthCheckResultRepository::class);
        $repository->method('findStatusCountsByDate')->willReturn($trend);

        return $repository;
    }

    private function createChartBuilder(): ChartBuilderInterface
    {
        $chartBuilder = $this->createStub(ChartBuilderInterface::class);
        $chartBuilder->method('createChart')->willReturnCallback(fn (string $type) => new Chart($type));

        return $chartBuilder;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    public function testBuildReturnsNullWithoutAnyHistory(): void
    {
        $builder = new HealthCheckTrendChartBuilder(
            $this->createRepository(['dates' => [], 'series' => [HealthCheckResult::STATUS_OK => [], HealthCheckResult::STATUS_WARNING => [], HealthCheckResult::STATUS_ERROR => []]]),
            $this->createChartBuilder(),
            $this->createTranslator(),
        );

        $this->assertNull($builder->build());
    }

    public function testBuildSetsLabelsAndOneDatasetPerStatus(): void
    {
        $trend = [
            'dates' => ['2026-07-10', '2026-07-17'],
            'series' => [
                HealthCheckResult::STATUS_OK => [8, 10],
                HealthCheckResult::STATUS_WARNING => [2, 1],
                HealthCheckResult::STATUS_ERROR => [0, 1],
            ],
        ];

        $builder = new HealthCheckTrendChartBuilder($this->createRepository($trend), $this->createChartBuilder(), $this->createTranslator());

        $chart = $builder->build();

        $this->assertInstanceOf(Chart::class, $chart);
        $data = $chart->getData();
        $this->assertSame(['2026-07-10', '2026-07-17'], $data['labels']);
        $this->assertCount(3, $data['datasets']);
        $this->assertSame([8, 10], $data['datasets'][0]['data']);
        $this->assertSame([2, 1], $data['datasets'][1]['data']);
        $this->assertSame([0, 1], $data['datasets'][2]['data']);
    }
}
