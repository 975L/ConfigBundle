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
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Symfony\Component\Translation\TranslatableMessage;

abstract class AbstractMenuProvider implements MenuProviderInterface
{
    public function getMenuItems(): iterable
    {
        // Section title
        $section = $this->getSection();
        yield MenuItem::section(new TranslatableMessage($section['label'], [], $section['translation_domain']));

        // Dynamically generate menu items based on the menu array
        foreach ($this->getMenu() as $key => $menu) {
            yield MenuItem::linkTo($menu['controller'], new TranslatableMessage($menu['label'], [], $menu['translation_domain']), $menu['icon'])->setPermission('ROLE_ADMIN');
        }
    }
}
