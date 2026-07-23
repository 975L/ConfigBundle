<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\WhatsNewBuilder;
use c975L\ConfigBundle\Management\WhatsNewProviderInterface;
use c975L\UiBundle\Registry\WhatsNewRegistry;
use PHPUnit\Framework\TestCase;

class WhatsNewBuilderTest extends TestCase
{
    private function createEntry(string $date, array $description): array
    {
        return ['date' => new \DateTimeImmutable($date), 'description' => $description];
    }

    private function createProvider(array $entries): WhatsNewProviderInterface
    {
        $provider = $this->createStub(WhatsNewProviderInterface::class);
        $provider->method('getEntries')->willReturn($entries);

        return $provider;
    }

    private function createUiRegistry(array $entries = []): WhatsNewRegistry
    {
        $registry = $this->createStub(WhatsNewRegistry::class);
        $registry->method('all')->willReturn($entries);

        return $registry;
    }

    public function testGetAllMergesUiBundleAndProvidersSortedByDateDescending(): void
    {
        $provider = $this->createProvider([$this->createEntry('2026-01-01', ['old'])]);
        $registry = $this->createUiRegistry([$this->createEntry('2026-06-01', ['new'])]);
        $builder = new WhatsNewBuilder([$provider], $registry);

        $all = $builder->getAll();

        $this->assertSame(['2026-06-01', '2026-01-01'], array_map(fn (array $e) => $e['date']->format('Y-m-d'), $all));
    }

    public function testGetAllGroupsEntriesSharingTheSameDateAndMergesTheirDescriptions(): void
    {
        $provider = $this->createProvider([
            $this->createEntry('2026-06-01', ['from-provider']),
        ]);
        $registry = $this->createUiRegistry([
            $this->createEntry('2026-06-01', ['from-ui']),
        ]);
        $builder = new WhatsNewBuilder([$provider], $registry);

        $all = $builder->getAll();

        $this->assertCount(1, $all);
        $this->assertSame(['from-ui', 'from-provider'], $all[0]['description']);
    }

    // Always visible on the dashboard now (see management/index.html.twig) - a hard cap, not the "at least one full date" leniency this used to have, so a single verbose date can't flood the panel
    public function testGetLatestTruncatesADateExceedingTheCapInsteadOfIncludingItInFull(): void
    {
        $provider = $this->createProvider([
            $this->createEntry('2026-06-01', ['1', '2', '3', '4', '5', '6', '7', '8', '9']),
            $this->createEntry('2026-05-01', ['10']),
        ]);
        $builder = new WhatsNewBuilder([$provider], $this->createUiRegistry());

        $latest = $builder->getLatest(8);

        $this->assertCount(1, $latest);
        $this->assertSame('2026-06-01', $latest[0]['date']->format('Y-m-d'));
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], $latest[0]['description']);
    }

    // The dashboard's own default (no explicit $maxItems) stays a short, fixed-size list
    public function testGetLatestDefaultsToFiveLines(): void
    {
        $provider = $this->createProvider([
            $this->createEntry('2026-06-01', ['1', '2', '3']),
            $this->createEntry('2026-05-01', ['4', '5', '6']),
        ]);
        $builder = new WhatsNewBuilder([$provider], $this->createUiRegistry());

        $latest = $builder->getLatest();

        $this->assertSame(['1', '2', '3'], $latest[0]['description']);
        $this->assertSame(['4', '5'], $latest[1]['description']);
    }

    public function testGetLatestIncludesFurtherEntriesUntilTheCapIsReached(): void
    {
        $provider = $this->createProvider([
            $this->createEntry('2026-06-01', ['1', '2']),
            $this->createEntry('2026-05-01', ['3', '4']),
            $this->createEntry('2026-04-01', ['5', '6']),
        ]);
        $builder = new WhatsNewBuilder([$provider], $this->createUiRegistry());

        $latest = $builder->getLatest(4);

        $this->assertCount(2, $latest);
        $this->assertSame(['2026-06-01', '2026-05-01'], array_map(fn (array $e) => $e['date']->format('Y-m-d'), $latest));
    }
}
