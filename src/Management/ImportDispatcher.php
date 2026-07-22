<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Routes an uploaded content export (see ContentImportController) to whichever ImportProvider (bundles depending on ConfigBundle) declares support for its "kind"
class ImportDispatcher
{
    public function __construct(
        private readonly iterable $importProviders,
    ) {
    }

    // Returns null when no provider supports $kind yet (reported to the admin instead of silently ignored - see ContentImportController)
    public function dispatch(string $kind, array $items, ?string $filesDir = null): ?array
    {
        foreach ($this->importProviders as $provider) {
            if ($provider->supportsImport($kind)) {
                return $provider->import($items, $filesDir);
            }
        }

        return null;
    }
}
