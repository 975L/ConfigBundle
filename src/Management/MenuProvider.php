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

// To add a MenuProvider, you need to:
// add the Management Folder in the src/ folder of your bundle
// Create a MenuProvider.php file in it with a class that extends AbstractMenuProvider and implements the getMenuSection() and getMenus() methods
// You can also add getRoutesSection() and getRoutes() methods to add links to routes in the menu
// add the declaration of the Management folder in the services.yaml file of your bundle
// ConfigBundle will automatically detect the MenuProvider and add it to the menu of EasyAdmin

class MenuProvider extends AbstractMenuProvider
{
    public function getMenuSection(): array
    {
        return [
            'label' => 'label.config',
            'translation_domain' => 'config',
        ];
    }

    public function getMenus(): array
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
