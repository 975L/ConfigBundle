<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\ImportmapSpecifierLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class ImportmapSpecifierLocatorTest extends TestCase
{
    private string $projectDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/c975l-importmap-locator-' . uniqid();
        $this->filesystem->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    // Mimics an installed c975L bundle's own asset, the only place bare specifiers are looked for
    private function dumpBundleAsset(string $bundle, string $file, string $contents): void
    {
        $this->filesystem->dumpFile($this->projectDir . '/vendor/c975l/' . $bundle . '/assets/' . $file, $contents);
    }

    // Mimics a Symfony UX package as Composer installs it: an assets/package.json naming the specifier and pointing at its entry file
    private function dumpUxPackage(string $vendor, string $name, array $package): void
    {
        $this->filesystem->dumpFile(
            $this->projectDir . '/vendor/' . $vendor . '/' . $name . '/assets/package.json',
            json_encode($package)
        );
    }

    private function createLocator(): ImportmapSpecifierLocator
    {
        return new ImportmapSpecifierLocator($this->projectDir);
    }

    public function testFindBareSpecifiersReturnsNothingWhenNoBundleIsInstalled(): void
    {
        $this->assertSame([], $this->createLocator()->findBareSpecifiers());
    }

    // The four import forms the ecosystem's own controllers actually use
    public function testFindBareSpecifiersReadsEveryImportForm(): void
    {
        $this->dumpBundleAsset('config-bundle', 'controllers-admin.js', <<<'JS'
            import ChartjsController from '@symfony/ux-chartjs';
            import { Controller } from '@hotwired/stimulus';
            import 'sortablejs';
            export { default } from '@symfony/ux-live-component';
            JS);

        $this->assertSame(
            ['@hotwired/stimulus', '@symfony/ux-chartjs', '@symfony/ux-live-component', 'sortablejs'],
            $this->createLocator()->findBareSpecifiers()
        );
    }

    // AssetMapper resolves those from the importing file itself, they never need an entry of their own
    public function testFindBareSpecifiersSkipsRelativeAndRootedImports(): void
    {
        $this->dumpBundleAsset('config-bundle', 'controllers-admin.js', <<<'JS'
            import { addToolbarButton } from './js/block-toolbar.js';
            import '../styles/app.css';
            import '/assets/legacy.js';
            JS);

        $this->assertSame([], $this->createLocator()->findBareSpecifiers());
    }

    // A specifier imported by two bundles is one entry to add, not two
    public function testFindBareSpecifiersDeduplicatesAcrossBundles(): void
    {
        $this->dumpBundleAsset('config-bundle', 'controllers-admin.js', "import Chart from '@symfony/ux-chartjs';\n");
        $this->dumpBundleAsset('ui-bundle', 'js/menu.js', "import Chart from '@symfony/ux-chartjs';\n");

        $this->assertSame(['@symfony/ux-chartjs'], $this->createLocator()->findBareSpecifiers());
    }

    // Its specifier is often computed, and a literal one is resolved at call time, so a missing entry there doesn't take the whole module down
    public function testFindBareSpecifiersIgnoresDynamicImport(): void
    {
        $this->dumpBundleAsset('config-bundle', 'controllers-admin.js', "const editor = await import('@ckeditor/ckeditor5-build-classic');\n");

        $this->assertSame([], $this->createLocator()->findBareSpecifiers());
    }

    // Only the c975L bundles' own assets are scanned - a third-party package's internal imports are its own business
    public function testFindBareSpecifiersIgnoresNonC975lVendors(): void
    {
        $this->filesystem->dumpFile($this->projectDir . '/vendor/symfony/ux-chartjs/assets/dist/controller.js', "import 'chart.js';\n");

        $this->assertSame([], $this->createLocator()->findBareSpecifiers());
    }

    public function testResolvePathReturnsThePackageEntryFileRelativeToTheProject(): void
    {
        $this->dumpUxPackage('symfony', 'ux-chartjs', ['name' => '@symfony/ux-chartjs', 'main' => 'dist/controller.js']);

        $this->assertSame(
            './vendor/symfony/ux-chartjs/assets/dist/controller.js',
            $this->createLocator()->resolvePath('@symfony/ux-chartjs')
        );
    }

    // A CDN-only package, a typo... - reported by the caller rather than guessed at
    public function testResolvePathReturnsNullWhenNothingDeclaresTheSpecifier(): void
    {
        $this->dumpUxPackage('symfony', 'ux-chartjs', ['name' => '@symfony/ux-chartjs', 'main' => 'dist/controller.js']);

        $this->assertNull($this->createLocator()->resolvePath('@acme/not-installed'));
    }

    public function testResolvePathReturnsNullWhenThePackageDeclaresNoEntryFile(): void
    {
        $this->dumpUxPackage('symfony', 'ux-chartjs', ['name' => '@symfony/ux-chartjs']);

        $this->assertNull($this->createLocator()->resolvePath('@symfony/ux-chartjs'));
    }

    public function testResolvePathIgnoresAMalformedPackageJson(): void
    {
        $this->filesystem->dumpFile($this->projectDir . '/vendor/symfony/ux-chartjs/assets/package.json', '{ not json');

        $this->assertNull($this->createLocator()->resolvePath('@symfony/ux-chartjs'));
    }
}
