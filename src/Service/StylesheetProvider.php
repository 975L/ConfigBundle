<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Service;

use c975L\UiBundle\Contract\BundleStylesheetManagementProviderInterface;

class StylesheetProvider implements BundleStylesheetManagementProviderInterface
{
    public function getManagementStylesheets(): array
    {
        return [
            'bundles/c975lconfig/css/management.min.css',
        ];
    }
}
