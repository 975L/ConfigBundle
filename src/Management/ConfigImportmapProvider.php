<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// ConfigBundle's own admin.js entry (dashboard guided tour + Health check trend chart) - dogfoods ImportmapProviderInterface instead of requiring the manual importmap.php edit this used to document
class ConfigImportmapProvider implements ImportmapProviderInterface
{
    public function getAdminImportmapEntries(): array
    {
        return [
            '@c975l/config-bundle/controllers-admin.js' => [
                'path' => './vendor/c975l/config-bundle/assets/controllers-admin.js',
                'entrypoint' => true,
            ],
        ];
    }

    public function getImportmapEntries(): array
    {
        return [];
    }
}
