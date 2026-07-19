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
use c975L\ConfigBundle\Management\MenuProviderInterface;

// To add a MenuProvider, you need to: add the Management Folder in the src/ folder of your bundle; create a MenuProvider.php file in it with a class that implements MenuProviderInterface, providing getMenuSection(), getMenus() and getLinks() methods; getLinks() can return [] if your bundle has no links to routes to expose (all bundles' links are merged into a single alphabetically-sorted section); add the declaration of the Management folder in the services.yaml file of your bundle; ConfigBundle will automatically detect the MenuProvider and add it to the menu of EasyAdmin

class MenuProvider implements MenuProviderInterface
{
    public function getMenuSection(): array
    {
        return [
            'label' => 'label.management',
            'translation_domain' => 'site',
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

    public function getLinks(): array
    {
        return [
            'whatsnew' => [
                'label' => 'label.whatsnew',
                'name' => 'management_whatsnew_index',
                'translation_domain' => 'config',
                'icon' => 'fa fa-bullhorn',
            ],
        ];
    }
}
