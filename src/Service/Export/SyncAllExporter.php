<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Service\Export;

use c975L\ConfigBundle\Management\ExportProviderInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Gathers every registered ExportProvider (bundles depending on ConfigBundle, auto-tagged - see ExportProviderInterface) into a single re-importable zip, exposed as the "export sync all" dashboard shortcut (see ConfigShortcutController::exportSyncAll). A provider that isn't installed simply doesn't contribute a section, no configuration needed on either side
class SyncAllExporter
{
    public function __construct(
        private readonly iterable $exportProviders,
        private readonly ContentExporter $contentExporter,
    ) {
    }

    public function export(): BinaryFileResponse
    {
        $exports = [];
        foreach ($this->exportProviders as $provider) {
            $data = $provider->exportAll();
            $exports[] = [
                'kind' => $provider->getKind(),
                'items' => $data['items'],
                'files' => $data['files'] ?? [],
            ];
        }

        return $this->contentExporter->exportMultiple($exports);
    }
}
