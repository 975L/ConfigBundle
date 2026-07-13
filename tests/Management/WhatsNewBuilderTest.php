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

    public function testGetLatestCapsTotalDescriptionLinesButAlwaysIncludesAtLeastOneEntry(): void
    {
        $provider = $this->createProvider([
            $this->createEntry('2026-06-01', ['1', '2', '3', '4', '5', '6', '7', '8', '9']),
            $this->createEntry('2026-05-01', ['10']),
        ]);
        $builder = new WhatsNewBuilder([$provider], $this->createUiRegistry());

        $latest = $builder->getLatest(8);

        // The first entry alone (9 lines) already exceeds the cap of 8, but is still included alone
        $this->assertCount(1, $latest);
        $this->assertSame('2026-06-01', $latest[0]['date']->format('Y-m-d'));
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
