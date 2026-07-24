<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Implement this to have your bundle's own AssetMapper importmap.php entries (a Stimulus controller shipped in assets/, typically) added automatically to the consuming app - collected by ImportmapRegistry and written by the c975l:config:check-importmap command (wired into composer.json's post-update-cmd), see readme. Split in two methods mirroring UiBundle's BundleScriptAdminProviderInterface/BundleScriptProviderInterface admin/non-admin distinction, so each entry's purpose stays explicit at the declaration site - both are merged into the same importmap.php by ImportmapRegistry, the split only matters to the reader.
interface ImportmapProviderInterface
{
    // Entries for scripts loaded on the /management dashboard only (typically also returned by BundleScriptAdminProviderInterface::getAdminScripts()). Import name (e.g. '@c975l/my-bundle/controllers-admin.js') => ['path' => string, 'entrypoint' => bool]. 'path' is relative to the project root exactly as it should appear in importmap.php (e.g. './vendor/c975l/my-bundle/assets/controllers-admin.js'). Return [] if none.
    public function getAdminImportmapEntries(): array;

    // Entries for scripts used elsewhere - the site's front-end (typically also returned by BundleScriptProviderInterface::getScripts()) or any other AssetMapper dependency needing an importmap.php entry. Same shape as getAdminImportmapEntries(). Return [] if none.
    public function getImportmapEntries(): array;
}
