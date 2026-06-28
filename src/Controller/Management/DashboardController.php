<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/management', routeName: 'management')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly iterable $menuProviders,
        private readonly ConfigServiceInterface $configService,
    ) {}

    public function index(): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-needed'));

        $menus = [];
        foreach ($this->menuProviders as $provider) {
            $menus = array_merge($menus, $provider->getMenus());
        }

        $routes = [];
        foreach ($this->menuProviders as $provider) {
            if (method_exists($provider, 'getRoutes')) {
                $routes = array_merge($routes, $provider->getRoutes());
            }
        }

        return $this->render(
            '@c975LConfig/management/index.html.twig',
            [
                'menus' => $menus,
                'routes' => $routes,
            ]
        );
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="' . $this->configService->get('site-favicon') . '">' . $this->configService->get('site-name'))
            ->setFaviconPath($this->configService->get('site-favicon'))
            ->setTranslationDomain('config')
        ;
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addAssetMapperEntry('app')
            ->addJsFile(Asset::fromEasyAdminAssetPackage('field-text-editor.js'))
            ->addCssFile(Asset::fromEasyAdminAssetPackage('field-text-editor.css'));
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('label.dashboard', 'fa fa-home')->setPermission($this->configService->get('site-role-needed'));

        // Menu from bundles
        foreach ($this->menuProviders as $provider) {
            yield from $provider->getMenuItems();
        }

        yield MenuItem::section('label.user');
        yield MenuItem::linkToLogout('label.signout', 'fa fa-exit');
    }
}
