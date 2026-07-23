<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service\Export;

use c975L\ConfigBundle\Service\Export\ContentExporter;
use PHPUnit\Framework\TestCase;

class ContentExporterTest extends TestCase
{
    public function testExportReturnsADownloadableZipContainingTheManifest(): void
    {
        $items = [['slug' => 'home', 'title' => 'Home']];

        $response = (new ContentExporter())->export('site_page', $items);

        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename=site_page_', $response->headers->get('Content-Disposition'));

        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive();
        $zip->open($path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame('site_page', $manifest['kind']);
        $this->assertSame($items, $manifest['items']);
        $this->assertArrayHasKey('exportedAt', $manifest);

        unlink($path);
    }

    public function testExportEmbedsReferencedFilesInTheZip(): void
    {
        $sourcePath = tempnam(sys_get_temp_dir(), 'content_exporter_test_');
        file_put_contents($sourcePath, 'binary-content');

        $response = (new ContentExporter())->export('site_page', [], ['files/photo.jpg' => $sourcePath]);

        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive();
        $zip->open($path);
        $this->assertSame('binary-content', $zip->getFromName('files/photo.jpg'));
        $zip->close();

        unlink($sourcePath);
        unlink($path);
    }

    public function testExportMultipleBundlesEverySectionUnderOneManifest(): void
    {
        $exports = [
            ['kind' => 'site_page', 'items' => [['slug' => 'home']]],
            ['kind' => 'site_config', 'items' => [['slug' => 'site-title']], 'files' => []],
        ];

        $response = (new ContentExporter())->exportMultiple($exports);

        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename=sync_all_', $response->headers->get('Content-Disposition'));

        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive();
        $zip->open($path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertArrayHasKey('exportedAt', $manifest);
        $this->assertSame([
            ['kind' => 'site_page', 'items' => [['slug' => 'home']]],
            ['kind' => 'site_config', 'items' => [['slug' => 'site-title']]],
        ], $manifest['exports']);

        unlink($path);
    }

    public function testExportMultipleEmbedsFilesFromEverySection(): void
    {
        $sourcePath = tempnam(sys_get_temp_dir(), 'content_exporter_test_');
        file_put_contents($sourcePath, 'binary-content');

        $exports = [
            ['kind' => 'site_page', 'items' => [], 'files' => ['files/photo.jpg' => $sourcePath]],
            ['kind' => 'site_font', 'items' => []],
        ];

        $response = (new ContentExporter())->exportMultiple($exports);

        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive();
        $zip->open($path);
        $this->assertSame('binary-content', $zip->getFromName('files/photo.jpg'));
        $zip->close();

        unlink($sourcePath);
        unlink($path);
    }
}
