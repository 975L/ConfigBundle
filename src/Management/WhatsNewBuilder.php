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

// Merges the whatsnew entries contributed by every WhatsNewProvider (bundles depending on ConfigBundle)
// plus UiBundle's own providers (UiBundle cannot depend on ConfigBundle, see WhatsNewRegistry), sorted by date desc
class WhatsNewBuilder
{
    public function __construct(
        private readonly iterable $whatsNewProviders,
        private readonly WhatsNewRegistry $uiWhatsNewRegistry,
    ) {
    }

    // Returns the $limit most recent entries across all bundles
    public function getLatest(int $limit = 5): array
    {
        return \array_slice($this->getAll(), 0, $limit);
    }

    // Returns every entry across all bundles, sorted by date desc
    public function getAll(): array
    {
        $entries = array_merge(
            $this->uiWhatsNewRegistry->all(),
            ProviderMerger::merge($this->whatsNewProviders, fn (WhatsNewProviderInterface $provider) => $provider->getEntries()),
        );

        usort($entries, fn (array $a, array $b) => $b['date'] <=> $a['date']);

        return $entries;
    }
}
