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
use c975L\ConfigBundle\Service\Export\ConfigSqlExporter;
use c975L\ConfigBundle\Service\Export\SyncAllExporter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigSqlExporter $configSqlExporter,
        private readonly SyncAllExporter $syncAllExporter,
        private readonly TranslatorInterface $translator,
    ) {
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
}
