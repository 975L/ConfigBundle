<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Symfony\Component\Translation\TranslatableMessage;

abstract class AbstractMenuProvider implements MenuProviderInterface
{
    public function __construct(
        protected readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getMenuItems(): iterable
    {
        // Section title
        $section = $this->getMenuSection();
        yield MenuItem::section(new TranslatableMessage($section['label'], [], $section['translation_domain']));

        // Dynamically generate menu items based on the menu array
        foreach ($this->getMenus() as $key => $menu) {
            yield MenuItem::linkTo($menu['controller'], new TranslatableMessage($menu['label'], [], $menu['translation_domain']), $menu['icon'])->setPermission($this->configService->get('site-role-needed'));
        }

        // Route section title
        if (method_exists($this, 'getRouteSection')) {
            $section = $this->getRouteSection();
            yield MenuItem::section(new TranslatableMessage($section['label'], [], $section['translation_domain']));

        }

        // Dynamically generate menu items based on the routes array
        if (method_exists($this, 'getRoutes')) {
            foreach ($this->getRoutes() as $key => $route) {
                yield MenuItem::linkToRoute(new TranslatableMessage($route['label'], [], $route['translation_domain']), $route['icon'], $route['name'])->setLinkTarget('_blank');
            }
        }
    }
}
