<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service\Export;

use c975L\ConfigBundle\Management\ExportProviderInterface;
use c975L\ConfigBundle\Service\Export\ContentExporter;
use c975L\ConfigBundle\Service\Export\SyncAllExporter;
use PHPUnit\Framework\TestCase;

class SyncAllExporterTest extends TestCase
{
    public function testExportCollectsEveryProviderIntoOneMultiKindZip(): void
    {
        $pageProvider = $this->createStub(ExportProviderInterface::class);
        $pageProvider->method('getKind')->willReturn('site_page');
        $pageProvider->method('exportAll')->willReturn(['items' => [['slug' => 'home']], 'files' => ['files/a.jpg' => '/tmp/a.jpg']]);

        $configProvider = $this->createStub(ExportProviderInterface::class);
        $configProvider->method('getKind')->willReturn('site_config');
        $configProvider->method('exportAll')->willReturn(['items' => [['slug' => 'site-title']]]);

        $contentExporter = $this->createMock(ContentExporter::class);
        $contentExporter->expects($this->once())
            ->method('exportMultiple')
            ->with([
                ['kind' => 'site_page', 'items' => [['slug' => 'home']], 'files' => ['files/a.jpg' => '/tmp/a.jpg']],
                ['kind' => 'site_config', 'items' => [['slug' => 'site-title']], 'files' => []],
            ]);

        (new SyncAllExporter([$pageProvider, $configProvider], $contentExporter))->export();
    }

    public function testExportWorksWithNoProviderRegistered(): void
    {
        $contentExporter = $this->createMock(ContentExporter::class);
        $contentExporter->expects($this->once())->method('exportMultiple')->with([]);

        (new SyncAllExporter([], $contentExporter))->export();
    }
}
