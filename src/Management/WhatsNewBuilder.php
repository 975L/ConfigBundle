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

    // Returns the most recent entries, hard-capped at $maxItems total description lines - always visible
    // on the dashboard now (see management/index.html.twig), so this stays a short, fixed-size list rather
    // than growing with whichever date happens to have the most changes; a date exceeding what's left is
    // truncated rather than included in full, the full history stays one click away (management_whatsnew_index)
    public function getLatest(int $maxItems = 5): array
    {
        $latest = [];
        $remaining = $maxItems;

        foreach ($this->getAll() as $entry) {
            if ($remaining <= 0) {
                break;
            }

            $descriptions = \array_slice($entry['description'], 0, $remaining);
            $latest[] = ['date' => $entry['date'], 'description' => $descriptions];
            $remaining -= \count($descriptions);
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
