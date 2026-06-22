<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[AdminDashboard(routePath: '/management', routeName: 'management')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly iterable $menuProviders,
    ) {}

    public function index(): Response
    {
        $menus = [];
        foreach ($this->menuProviders as $provider) {
            $menus = array_merge($menus, $provider->getMenu());
        }

        return $this->render(
            '@c975LConfig/management/index.html.twig',
            [
                'menus' => $menus,
            ]
        );
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/favicon.ico"> Website')
            ->setFaviconPath('/favicon.ico')
            ->setTranslationDomain('config')
        ;
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('label.dashboard', 'fa fa-home')->setPermission('ROLE_ADMIN');

        // Menu from bundles
        foreach ($this->menuProviders as $provider) {
            yield from $provider->getMenuItems();
        }

        yield MenuItem::section('label.user');
        yield MenuItem::linkToLogout('label.signout', 'fa fa-exit');
    }
}
