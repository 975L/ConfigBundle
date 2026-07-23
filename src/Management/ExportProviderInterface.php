<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// To add an ExportProvider, you need to: add the Management Folder in the src/ folder of your bundle; create a class implementing ExportProviderInterface; ConfigBundle will automatically detect it and include its whole content in the "export sync all" dashboard shortcut (see SyncAllExporter). Mirrors ImportProviderInterface on the way out - same "kind" values, same natural-key philosophy (the import side never trusts a raw id)
interface ExportProviderInterface
{
    // The string embedded in the export payload for this provider's items (see ContentExporter), stable across dev/prod (eg. "site_page")
    public function getKind(): string;

    // Same shapes ContentExporter::export() expects: 'items' (JSON-able array, one entry per exported entity) and 'files' (archive-relative path => disk path, empty for a kind that never carries files)
    public function exportAll(): array;
}
