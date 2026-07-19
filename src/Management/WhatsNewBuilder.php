<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use c975L\UiBundle\Registry\WhatsNewRegistry;

// Merges the whatsnew entries contributed by every WhatsNewProvider (bundles depending on ConfigBundle) plus UiBundle's own providers (UiBundle cannot depend on ConfigBundle, see WhatsNewRegistry), sorted by date desc
class WhatsNewBuilder
{
    public function __construct(
        private readonly iterable $whatsNewProviders,
        private readonly WhatsNewRegistry $uiWhatsNewRegistry,
    ) {
    }

    // Returns the most recent dates, capped at $maxItems total description lines (always includes at least one date, even if it alone exceeds the cap, to avoid an empty dashboard widget)
    public function getLatest(int $maxItems = 8): array
    {
        $latest = [];
        $count = 0;

        foreach ($this->getAll() as $entry) {
            if ($count > 0 && $count + \count($entry['description']) > $maxItems) {
                break;
            }

            $latest[] = $entry;
            $count += \count($entry['description']);
        }

        return $latest;
    }

    // Returns one entry per date across all bundles (their descriptions merged), sorted by date desc
    public function getAll(): array
    {
        $entries = array_merge(
            $this->uiWhatsNewRegistry->all(),
            ProviderMerger::merge($this->whatsNewProviders, fn (WhatsNewProviderInterface $provider) => $provider->getEntries()),
        );

        $groupedByDate = [];
        foreach ($entries as $entry) {
            $key = $entry['date']->format('Y-m-d');
            $groupedByDate[$key] ??= ['date' => $entry['date'], 'description' => []];
            $groupedByDate[$key]['description'] = array_merge($groupedByDate[$key]['description'], $entry['description']);
        }

        $groupedByDate = array_values($groupedByDate);
        usort($groupedByDate, fn (array $a, array $b) => $b['date'] <=> $a['date']);

        return $groupedByDate;
    }
}
