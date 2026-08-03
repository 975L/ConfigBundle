<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Management\ExportProviderInterface;
use c975L\ConfigBundle\Repository\RedirectRepository;

// Serializes Redirects (fromPath/toUrl/permanent/gone) into the shape ContentExporter/RedirectImportProvider expect, for the "export sync all" dashboard shortcut (see ConfigBundle's SyncAllExporter). No files: a Redirect carries no upload of its own
class RedirectExportProvider implements ExportProviderInterface
{
    public function __construct(
        private readonly RedirectRepository $redirectRepository,
    ) {
    }

    public function getKind(): string
    {
        return RedirectImportProvider::KIND;
    }

    public function exportAll(): array
    {
        return $this->serialize($this->redirectRepository->findAll());
    }

    // @param iterable<Redirect> $redirects
    public function serialize(iterable $redirects): array
    {
        $items = [];
        foreach ($redirects as $redirect) {
            $items[] = [
                'fromPath' => $redirect->getFromPath(),
                'toUrl' => $redirect->getToUrl(),
                'permanent' => $redirect->isPermanent(),
                'gone' => $redirect->isGone(),
            ];
        }

        return ['items' => $items, 'files' => []];
    }
}
