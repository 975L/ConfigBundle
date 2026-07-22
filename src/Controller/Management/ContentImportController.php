<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Controller\Management;

use c975L\ConfigBundle\Management\ImportDispatcher;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

// Uploads a zip export produced by a bundle's own "export selection" action (see eg. SiteBundle's PageCrudController::exportSelection) and routes it to whichever ImportProvider declares support for its "kind" - restricted to ROLE_SUPER_ADMIN (not the configurable site-role-admin) since it writes arbitrary content straight into this environment's database, unlike the dev-only export side (see PageCrudController::configureActions())
class ContentImportController extends AbstractController
{
    // Real files, no base64 bloat (see ContentExporter) - still generous since a selection can bundle several images/fonts
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

    // EasyAdmin prefixes this with the Dashboard's own route name, giving management_content_import_index
    public const IMPORT_ROUTE = 'management_content_import_index';

    public function __construct(
        private readonly ImportDispatcher $importDispatcher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AdminRoute(path: '/content-import', name: 'content_import_index', options: ['methods' => ['GET', 'POST']])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($request->isMethod('POST')) {
            return $this->handleImport($request);
        }

        return $this->render('@c975LConfig/management/content_import.html.twig');
    }

    private function handleImport(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::IMPORT_ROUTE, $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('flash.content_import_invalid_token', [], 'config'));

            return $this->redirectToRoute(self::IMPORT_ROUTE);
        }

        $file = $request->files->get('export');
        if (null === $file || !$file->isValid()) {
            $this->addFlash('danger', $this->translator->trans('flash.content_import_no_file', [], 'config'));

            return $this->redirectToRoute(self::IMPORT_ROUTE);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $this->addFlash('danger', $this->translator->trans('flash.content_import_file_too_large', [], 'config'));

            return $this->redirectToRoute(self::IMPORT_ROUTE);
        }

        $extractDir = $this->extractZip($file);
        if (null === $extractDir) {
            $this->addFlash('danger', $this->translator->trans('flash.content_import_invalid_zip', [], 'config'));

            return $this->redirectToRoute(self::IMPORT_ROUTE);
        }

        try {
            $manifestPath = $extractDir . '/manifest.json';
            $payload = is_file($manifestPath) ? $this->decodeManifest($manifestPath) : null;

            if (!\is_array($payload) || !isset($payload['kind'], $payload['items']) || !\is_string($payload['kind']) || !\is_array($payload['items'])) {
                $this->addFlash('danger', $this->translator->trans('flash.content_import_invalid_json', [], 'config'));

                return $this->redirectToRoute(self::IMPORT_ROUTE);
            }

            $result = $this->importDispatcher->dispatch($payload['kind'], $payload['items'], $extractDir);
            if (null === $result) {
                $this->addFlash('danger', $this->translator->trans('flash.content_import_unsupported_kind', ['%kind%' => $payload['kind']], 'config'));

                return $this->redirectToRoute(self::IMPORT_ROUTE);
            }

            $this->addFlash('success', $this->translator->trans('flash.content_import_success', [
                '%created%' => $result['created'],
                '%updated%' => $result['updated'],
            ], 'config'));

            return $this->redirectToRoute(self::IMPORT_ROUTE);
        } finally {
            $this->removeDirectory($extractDir);
        }
    }

    private function decodeManifest(string $path): ?array
    {
        try {
            return json_decode(file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    // Opens the uploaded file as a zip and extracts it into a fresh temp directory, rejecting any entry whose name would resolve outside of it ("zip slip") - returns null on anything invalid (not a zip, empty, unsafe entry name) rather than throwing, so the caller can show a normal flash message
    private function extractZip(UploadedFile $file): ?string
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($file->getPathname())) {
            return null;
        }

        $extractDir = sys_get_temp_dir() . '/content_import_' . bin2hex(random_bytes(8));
        mkdir($extractDir, 0777, true);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (null === $name || str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                $this->removeDirectory($extractDir);

                return null;
            }
        }

        $zip->extractTo($extractDir);
        $zip->close();

        return $extractDir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
