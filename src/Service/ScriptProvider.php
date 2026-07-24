<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Service;

use c975L\UiBundle\Contract\BundleScriptAdminProviderInterface;

// ConfigBundle's first admin-only JS (the onboarding tour, see assets/js/onboarding-tour.js) - tagged 'ui.admin_script' in services.yaml, picked up the same way UiBundle/SiteBundle already register their own
class ScriptProvider implements BundleScriptAdminProviderInterface
{
    public function getAdminScripts(): array
    {
        return [
            '@c975l/config-bundle/controllers-admin.js',
        ];
    }
}
