<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Exposes the backoffice dashboard's own route, so it can be picked as a SiteBundle Menu item (navbar/footer) - e.g. a small "Admin" link. Access to '/management' itself stays gated by DashboardController::index() denyAccessUnlessGranted(), the menu link is just a shortcut to it
class LinkableRouteProvider implements LinkableRouteProviderInterface
{
    public function getLinkableRoutes(): array
    {
        return [
            'management' => [
                'label' => 'label.dashboard',
                'translation_domain' => 'config',
            ],
        ];
    }
}
