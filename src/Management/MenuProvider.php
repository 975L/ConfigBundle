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
use c975L\ConfigBundle\Controller\Management\RedirectCrudController;
use c975L\ConfigBundle\Controller\Management\UserCrudController;
use c975L\ConfigBundle\Service\ConfigServiceInterface;

// To add a MenuProvider, you need to: add the Management Folder in the src/ folder of your bundle; create a MenuProvider.php file in it with a class that implements MenuProviderInterface, providing getMenuSection(), getMenus() and getLinks() methods; getLinks() can return [] if your bundle has no links to routes to expose (all bundles' links are merged into a single alphabetically-sorted section); add the declaration of the Management folder in the services.yaml file of your bundle; ConfigBundle will automatically detect the MenuProvider and add it to the menu of EasyAdmin

class MenuProvider implements MenuProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

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
                'description' => 'description.config',
            ],
            // Declared here rather than by SiteBundle as it used to be: an app running Config+Ui plus a satellite bundle but no site foundation still has accounts to manage
            'user' => [
                'controller' => UserCrudController::class,
                'label' => 'label.users',
                'translation_domain' => 'config',
                'icon' => 'fas fa-users',
                // Same key as user_crud_index.html.twig/user_crud_edit.html.twig's own explanatory text - one text, reused, not a separate onboarding-only string (see MenuProviderInterface::getMenus())
                'description' => 'label.info_user',
            ],
            // Also declared here rather than by SiteBundle: a url that changed needs a redirect whether it was a Page's or a product's, and the rows answer before the router, so they never depended on page management
            'redirect' => [
                'controller' => RedirectCrudController::class,
                'label' => 'label.redirects',
                'translation_domain' => 'config',
                'icon' => 'fas fa-arrow-right',
                'tier' => 'advanced',
                'description' => 'label.info_redirect',
            ],
        ];
    }

    public function getLinks(): array
    {
        $links = [
            'whatsnew' => [
                'label' => 'label.whatsnew',
                'name' => 'management_whatsnew_index',
                'translation_domain' => 'config',
                'icon' => 'fa fa-bullhorn',
                'description' => 'description.whatsnew',
            ],
            'health_check' => [
                'label' => 'label.health_check',
                'name' => 'management_health_check_index',
                'translation_domain' => 'config',
                'icon' => 'fa fa-heart-pulse',
                'description' => 'description.health_check',
            ],
            'content_import' => [
                'label' => 'label.content_import',
                'name' => 'management_content_import_index',
                'translation_domain' => 'config',
                'icon' => 'fa fa-file-import',
                'role' => 'ROLE_SUPER_ADMIN',
                // Same key as content_import.html.twig's own explanatory text
                'description' => 'label.content_import_help',
            ],
        ];

        // Link to the site itself, using its own "name"/"url" configs - pinned so it always stays at the very bottom of the links section, regardless of its label; omitted while the site's url isn't configured yet
        $siteUrl = $this->configService->get('site-url');
        if ($siteUrl) {
            $links['site'] = [
                'label' => 'label.site_link',
                'label_parameters' => ['%name%' => $this->configService->get('site-name') ?: $siteUrl],
                'url' => $siteUrl,
                'translation_domain' => 'config',
                'icon' => 'fa fa-globe',
                'target' => '_blank',
                'pinned' => true,
                // No local page to reuse text from (leaves the admin entirely) - its own dedicated key, same as UiBundle's "block_showcase" link
                'description' => 'description.site_link',
            ];
        }

        return $links;
    }
}
