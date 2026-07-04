<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\MenuBuilder;
use c975L\ConfigBundle\Management\WhatsNewBuilder;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\UiBundle\Registry\ScriptAdminRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/management', routeName: 'management')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly MenuBuilder $menuBuilder,
        private readonly WhatsNewBuilder $whatsNewBuilder,
        private readonly ConfigServiceInterface $configService,
        private readonly ScriptAdminRegistry $scriptAdminRegistry,
        private readonly ConfigRepository $configRepository,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {}

    public function index(): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-needed'));

        return $this->render(
            '@c975LConfig/management/index.html.twig',
            [
                'menus' => $this->menuBuilder->getMenus(),
                'routes' => $this->menuBuilder->getLinks(),
                'alerts' => $this->getAlerts(),
                'whatsNew' => $this->whatsNewBuilder->getLatest(5),
            ]
        );
    }

    // Groups configs still missing a value despite being flagged with a severity, by severity (danger first)
    private function getAlerts(): array
    {
        $alerts = [
            Config::SEVERITY_DANGER => [],
            Config::SEVERITY_WARNING => [],
            Config::SEVERITY_INFO => [],
        ];

        foreach ($this->configRepository->findRequiringAttention() as $config) {
            $alerts[$config->getSeverity()][] = [
                'label' => $config->getLabel(),
                'description' => $config->getDescription(),
                'url' => $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(ConfigCrudController::class)
                    ->setAction(Action::EDIT)
                    ->setEntityId($config->getId())
                    ->generateUrl(),
            ];
        }

        return $alerts;
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="' . $this->configService->get('site-favicon') . '">' . $this->configService->get('site-name'))
            ->setFaviconPath($this->configService->get('site-favicon'))
            ->setTranslationDomain('config')
        ;
    }

    // Each bundle providing EasyAdmin/Stimulus controllers contributes its controllers-admin.js via
    // BundleScriptAdminProviderInterface (c975L/UiBundle) - each one starts its own independent Stimulus app.
    // Each entry also needs a matching 'entrypoint' => true line in the app's importmap.php.
    public function configureAssets(): Assets
    {
        $assets = Assets::new()
            ->addJsFile(Asset::fromEasyAdminAssetPackage('field-text-editor.js'))
            ->addCssFile(Asset::fromEasyAdminAssetPackage('field-text-editor.css'));

        foreach ($this->scriptAdminRegistry->all() as $script) {
            $assets->addAssetMapperEntry($script);
        }

        return $assets;
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('label.dashboard', 'fa fa-home')->setPermission($this->configService->get('site-role-needed'));

        // Menu from bundles, grouped by section and sorted alphabetically
        yield from $this->menuBuilder->getMenuItems();

        yield MenuItem::section('label.user');
        yield MenuItem::linkToLogout('label.signout', 'fa fa-exit');
    }
}
