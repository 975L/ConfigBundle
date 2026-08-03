<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Command\ExportTablesCommand;
use c975L\ConfigBundle\Management\SitemapWriter;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ConfigSqlExporter;
use c975L\ConfigBundle\Service\Export\SyncAllExporter;
use c975L\UiBundle\Repository\FormRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigShortcutController extends AbstractController
{
    // EasyAdmin prefixes this with the Dashboard's own route name, giving management_config_clear_cache
    public const CLEAR_CACHE_ROUTE = 'management_config_clear_cache';
    public const EXPORT_SQL_ROUTE = 'management_config_export_sql_shortcut';
    public const EXPORT_SYNC_ALL_ROUTE = 'management_config_export_sync_all_shortcut';
    public const SITEMAPS_CREATE_ROUTE = 'management_config_sitemaps_create';
    public const EXPORT_TABLES_ROUTE = 'management_config_export_tables';
    public const REGISTRATION_ENABLED_TOGGLE_ROUTE = 'management_config_user_registration_enabled_toggle';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigSqlExporter $configSqlExporter,
        private readonly SyncAllExporter $syncAllExporter,
        private readonly SitemapWriter $sitemapWriter,
        private readonly ExportTablesCommand $exportTablesCommand,
        private readonly FormRepository $formRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Streamed back directly, writeFile being false so nothing lingers in var/export
    #[AdminRoute(
        path: '/config/export-tables',
        name: 'config_export_tables',
        options: ['methods' => ['POST']]
    )]
    public function exportTables(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid(self::EXPORT_TABLES_ROUTE, $request->request->get('_token'))) {
            return $this->redirectToRoute('management');
        }

        $result = $this->exportTablesCommand->exportTables(writeFile: false);

        if (null !== $result['error']) {
            $this->addFlash('danger', $result['error']);

            return $this->redirectToRoute('management');
        }

        if (empty($result['tables'])) {
            $this->addFlash('warning', $result['message']);

            return $this->redirectToRoute('management');
        }

        return new Response($result['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="site_' . date('Ymd_His') . '.sql"',
        ]);
    }

    // Flips the "register" c975L\UiBundle\Entity\Form's $enabled flag - same lever the scaffolded RegisterFormAction's Form is checked against by FormController before building/submitting it
    #[AdminRoute(
        path: '/config/user-registration-enabled-toggle',
        name: 'config_user_registration_enabled_toggle',
        options: ['methods' => ['POST']]
    )]
    public function registrationEnabledToggle(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        $form = $this->formRepository->findOneBy(['name' => 'register']);
        if (null !== $form && $this->isCsrfTokenValid(self::REGISTRATION_ENABLED_TOGGLE_ROUTE, $request->request->get('_token'))) {
            $enabled = !$form->isEnabled();
            $form->setEnabled($enabled);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans(
                $enabled ? 'flash.user_registration_enabled' : 'flash.user_registration_disabled',
                [],
                'config',
            ));
        }

        return $this->redirectToRoute('management');
    }

    #[AdminRoute(
        path: '/config/clear-cache',
        name: 'config_clear_cache',
        options: ['methods' => ['POST']]
    )]
    public function clearCache(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid(self::CLEAR_CACHE_ROUTE, $request->request->get('_token'))) {
            $this->configService->invalidateCache();
            $this->addFlash('success', $this->translator->trans('flash.config_cache_cleared', [], 'config'));
        }

        return $this->redirectToRoute('management');
    }

    // Downloads the site_config SQL export directly (see ConfigSqlExporter); same non-destructive upsert used by ConfigCrudController::exportSql, exposed as a dashboard shortcut so it doesn't require opening the Config CRUD
    #[AdminRoute(
        path: '/config/export-sql-shortcut',
        name: 'config_export_sql_shortcut',
        options: ['methods' => ['POST']]
    )]
    public function exportSql(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        if (!$this->isCsrfTokenValid(self::EXPORT_SQL_ROUTE, $request->request->get('_token'))) {
            return $this->redirectToRoute('management');
        }

        return $this->configSqlExporter->export();
    }

    // Downloads a single re-importable zip bundling the whole content of every installed bundle contributing an ExportProvider (site_page, site_font, gallery_category, site_config...) - the "sync everything to prod in one click" shortcut, re-uploaded via ContentImportController the same way each bundle's own "export selection" already is
    #[AdminRoute(
        path: '/config/export-sync-all-shortcut',
        name: 'config_export_sync_all_shortcut',
        options: ['methods' => ['POST']]
    )]
    public function exportSyncAll(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        if (!$this->isCsrfTokenValid(self::EXPORT_SYNC_ALL_ROUTE, $request->request->get('_token'))) {
            return $this->redirectToRoute('management');
        }

        return $this->syncAllExporter->export();
    }

    // Regenerates every registered SitemapProvider's own sitemap plus the index, same job as the c975l:sitemaps:create command - so it doesn't have to wait for the next scheduler run after publishing something
    #[AdminRoute(
        path: '/config/sitemaps-create',
        name: 'config_sitemaps_create',
        options: ['methods' => ['POST']]
    )]
    public function createSitemaps(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid(self::SITEMAPS_CREATE_ROUTE, $request->request->get('_token'))) {
            // An unwritable public/ folder or two providers sharing a name must be reported, not turned into a 500 nor hidden behind a success flash
            try {
                $this->sitemapWriter->write();
                $this->addFlash('success', $this->translator->trans('flash.config_sitemaps_created', [], 'config'));
            } catch (IOExceptionInterface | \LogicException $e) {
                $this->addFlash('error', $this->translator->trans('flash.config_sitemaps_error', ['%error%' => $e->getMessage()], 'config'));
            }
        }

        return $this->redirectToRoute('management');
    }
}
