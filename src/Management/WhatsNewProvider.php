<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// To add a WhatsNewProvider, you need to:
// add the Management Folder in the src/ folder of your bundle
// Create a WhatsNewProvider.php file in it with a class that implements WhatsNewProviderInterface, providing getEntries()
// Store your entries in a config/whatsnew.json file, read via WhatsNewJsonReader::read()
// add the declaration of the Management folder in the services.yaml file of your bundle
// ConfigBundle will automatically detect the WhatsNewProvider and merge its entries into the dashboard

class WhatsNewProvider implements WhatsNewProviderInterface
{
    public function getEntries(): array
    {
        return WhatsNewJsonReader::read(\dirname(__DIR__, 2) . '/config/whatsnew.json');
    }
}
