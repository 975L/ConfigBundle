<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Controller\Management\ConfigCrudController;
use c975L\ConfigBundle\Management\AbstractMenuProvider;

class MenuProvider extends AbstractMenuProvider
{
    public function getSection(): array
    {
        return [
            'label' => 'label.config',
            'translation_domain' => 'config',
        ];
    }

    public function getMenu(): array
    {
        return [
            'config' => [
                'controller' => ConfigCrudController::class,
                'label' => 'label.config',
                'translation_domain' => 'config',
                'icon' => 'fa fa-cog',
            ],
        ];
    }
}
