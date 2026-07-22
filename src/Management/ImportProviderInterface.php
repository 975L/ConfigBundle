<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// To add an ImportProvider, you need to: add the Management Folder in the src/ folder of your bundle; create a class implementing ImportProviderInterface; ConfigBundle will automatically detect it and route to it any content_import upload whose "kind" it supports. Each provider owns its own upsert logic (matching a natural key like slug/name, never a raw autoincrement id - the whole point is that dev and prod ids never need to match, see ContentExporter)
interface ImportProviderInterface
{
    // $kind is the string embedded in the export payload (see ContentExporter::export()), stable across dev/prod (eg. "site_page")
    public function supportsImport(string $kind): bool;

    // $items are the payload's raw "items" array, one entry per exported entity. $filesDir is the directory the export's zip archive was extracted into (see ContentImportController) - any 'file' reference inside $items (eg. a Block's Media, a "files/xxx.jpg" path) is relative to it; null for a kind that never carries files. Returns ['created' => int, 'updated' => int]
    public function import(array $items, ?string $filesDir = null): array;
}
