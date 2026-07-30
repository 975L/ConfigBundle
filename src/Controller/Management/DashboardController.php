<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Management\AlertBuilder;
use c975L\ConfigBundle\Management\DashboardWidgetBuilder;
use c975L\ConfigBundle\Management\EssentialActionBuilder;
use c975L\ConfigBundle\Management\GuidedProjectBuilder;
use c975L\ConfigBundle\Management\GuidedProjectMountBuilder;
use c975L\ConfigBundle\Management\MenuBuilder;
use c975L\ConfigBundle\Management\OnboardingStepBuilder;
use c975L\ConfigBundle\Management\ShortcutBuilder;
use c975L\ConfigBundle\Management\WhatsNewBuilder;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\UiBundle\Registry\FormThemeRegistry;
use c975L\UiBundle\Registry\ScriptAdminRegistry;
use c975L\UiBundle\Registry\StylesheetManagementRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AdminDashboard(routePath: '/management', routeName: 'management')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly MenuBuilder $menuBuilder,
        private readonly WhatsNewBuilder $whatsNewBuilder,
        private readonly AlertBuilder $alertBuilder,
        private readonly ShortcutBuilder $shortcutBuilder,
        private readonly EssentialActionBuilder $essentialActionBuilder,
        private readonly DashboardWidgetBuilder $dashboardWidgetBuilder,
        private readonly OnboardingStepBuilder $onboardingStepBuilder,
        private readonly GuidedProjectBuilder $guidedProjectBuilder,
        private readonly GuidedProjectMountBuilder $guidedProjectMountBuilder,
        private readonly ConfigServiceInterface $configService,
        private readonly ScriptAdminRegistry $scriptAdminRegistry,
        private readonly StylesheetManagementRegistry $stylesheetManagementRegistry,
        private readonly FormThemeRegistry $formThemeRegistry,
        private readonly TranslatorInterface $translator,
        private readonly Packages $packages,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function index(): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        return $this->render(
            '@c975LConfig/management/index.html.twig',
            [
                'menus' => $this->menuBuilder->getMenus(),
                'routes' => $this->menuBuilder->getLinks(),
                'alerts' => $this->alertBuilder->getAlerts(),
                'shortcuts' => $this->shortcutBuilder->getShortcuts(),
                'whatsNew' => $this->whatsNewBuilder->getLatest(),
                'essentialActions' => $this->essentialActionBuilder->getActions(),
                'essentialActionsProgress' => $this->essentialActionBuilder->getProgress(),
                'widgets' => $this->dashboardWidgetBuilder->getWidgets(),
                'onboardingSteps' => $this->onboardingStepBuilder->getSteps(),
                'guidedProjects' => $this->guidedProjectBuilder->getProjects(),
            ]
        );
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            // Fixed path: c975L\SiteBundle's SiteGraphicCrudController always saves the favicon there (see UiMediaNamer)
            ->setTitle('<img src="/favicon.ico">' . $this->configService->get('site-name'))
            ->setFaviconPath('/favicon.ico')
            ->setTranslationDomain('config')
        ;
    }

    // EasyAdmin renders every CRUD form with "{% form_theme form with ea.crud.formThemes only %}" (see vendor/easycorp/.../crud/edit.html.twig) - the "only" keyword means the app-wide twig.form_themes config is never consulted there, so bundle-contributed form themes (Trix editor, icon picker, "used in"...) have to be injected into the Crud config itself instead, here, the single place every CRUD controller's own configureCrud() inherits its default from (see FormThemeProviderInterface for the extension point bundles implement to reach this).
    public function configureCrud(): Crud
    {
        $crud = Crud::new();
        foreach ($this->formThemeRegistry->all() as $formTheme) {
            $crud->addFormTheme($formTheme);
        }

        return $crud;
    }

    // Each bundle providing EasyAdmin/Stimulus controllers contributes its controllers-admin.js via BundleScriptAdminProviderInterface (c975L/UiBundle) - each one starts its own independent Stimulus app. Each entry also needs a matching 'entrypoint' => true line in the app's importmap.php.
    public function configureAssets(): Assets
    {
        $assets = Assets::new()
            ->addJsFile(Asset::fromEasyAdminAssetPackage('field-text-editor.js'))
            ->addCssFile(Asset::fromEasyAdminAssetPackage('field-text-editor.css'));

        foreach ($this->scriptAdminRegistry->all() as $script) {
            $assets->addAssetMapperEntry($script);
        }

        // In dev, keeps each bundle's stylesheet separate for instant reload on every CSS edit; in prod, links to the single file compiled by StylesheetCacheWarmer (c975L/UiBundle) instead
        if ($this->debug) {
            foreach ($this->stylesheetManagementRegistry->all() as $stylesheet) {
                $assets->addCssFile($stylesheet);
            }
        } else {
            $assets->addCssFile('bundles/build/admin.css');
        }

        // The guided-project panel has to survive the page loads a project walks the user through, so its mount element goes on every admin page, not just the dashboard - EasyAdmin renders this on all of them (see its layout.html.twig), which spares an override of that layout
        $assets->addHtmlContentToBody($this->guidedProjectMountBuilder->getHtml());

        return $assets;
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('label.dashboard', 'fa fa-home')->setPermission($this->configService->get('site-role-admin'));

        // Menu from bundles, grouped by section and sorted alphabetically
        yield from $this->menuBuilder->getMenuItems();

        yield MenuItem::section('label.user');
        yield MenuItem::linkToLogout('label.signout', 'fa fa-exit');

        // "Made by" logo, shown at the very bottom of the sidebar, only when both configs are filled
        $madeByLogo = $this->configService->get('site-made-by-logo');
        $madeByUrl = $this->configService->get('site-made-by-url');
        if ($madeByLogo && $madeByUrl) {
            $madeByLabel = htmlspecialchars($this->translator->trans('label.made_by', [], 'site'), ENT_QUOTES);
            // Through the asset packages: the label is raw HTML, so a relative path would resolve against /management/
            yield MenuItem::linkToUrl(
                sprintf(
                    '<span>%s</span><img src="%s" alt="" class="config-made-by-logo">',
                    $madeByLabel,
                    htmlspecialchars($this->packages->getUrl($madeByLogo), ENT_QUOTES),
                ),
                null,
                $madeByUrl,
            )->setLinkTarget('_blank')->setCssClass('menu-item-made-by');
        }
    }
}
