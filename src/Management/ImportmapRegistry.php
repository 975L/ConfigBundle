<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

// Merges the importmap entries contributed by every ImportmapProviderInterface (see readme)
class ImportmapRegistry
{
    private array $entries;

    // @param iterable<ImportmapProviderInterface> $providers
    public function __construct(iterable $providers)
    {
        $entries = [];
        foreach ($providers as $provider) {
            $entries = array_merge($entries, $provider->getAdminImportmapEntries(), $provider->getImportmapEntries());
        }
        $this->entries = $entries;
    }

    public function all(): array
    {
        return $this->entries;
    }
}
