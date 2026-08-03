<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\ScaffoldInstaller;
use c975L\UiBundle\Entity\Media;
use PHPUnit\Framework\TestCase;

class ScaffoldInstallerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/c975l-scaffold-installer-test-' . uniqid();
        mkdir($this->projectDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    // Recursively deletes a directory tree (no external dependency needed for this test-only cleanup)
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    // The counts alone, the list of files install() also returns being asserted on its own where it matters (Finder gives no guaranteed order)
    private function counts(array $result): array
    {
        unset($result['files'], $result['unmatched']);

        return $result;
    }

    // Fabricates vendor/c975l/<bundleName>/scaffold/{src,templates} with the given file => content map
    private function addScaffoldBundle(string $bundleName, array $files): void
    {
        foreach ($files as $relativePath => $content) {
            $target = $this->projectDir . '/vendor/c975l/' . $bundleName . '/scaffold/' . $relativePath;
            if (!is_dir(\dirname($target))) {
                mkdir(\dirname($target), 0775, true);
            }
            file_put_contents($target, $content);
        }
    }

    // The scaffolded class the reminder checks for before advising the import's removal
    private function addThemeProvider(): void
    {
        mkdir($this->projectDir . '/src/Service', 0775, true);
        file_put_contents($this->projectDir . '/src/Service/ThemeStylesheetProvider.php', '<?php');
    }

    // A brand-new project with no pre-existing files: every scaffold file is copied, nothing backed up
    public function testInstallCopiesEveryScaffoldFileWhenNoneExistYet(): void
    {
        $this->addScaffoldBundle('config-bundle', [
            'src/Controller/FooController.php' => 'foo',
            'templates/foo.html.twig' => 'bar',
        ]);
        $installer = new ScaffoldInstaller($this->projectDir);

        $result = $installer->install();

        $this->assertSame(['copied' => 2, 'backedUp' => 0, 'skipped' => 0], $this->counts($result));
        $this->assertFileExists($this->projectDir . '/src/Controller/FooController.php');
        $this->assertSame('foo', file_get_contents($this->projectDir . '/src/Controller/FooController.php'));
        $this->assertFileExists($this->projectDir . '/templates/foo.html.twig');
    }

    // A file already present at the target path is backed up under existingFiles/ instead of being erased
    public function testInstallBacksUpExistingFileInsteadOfOverwritingIt(): void
    {
        $this->addScaffoldBundle('site-bundle', ['src/Kernel.php' => 'new-content']);
        mkdir($this->projectDir . '/src', 0775, true);
        file_put_contents($this->projectDir . '/src/Kernel.php', 'original-content');
        $installer = new ScaffoldInstaller($this->projectDir);

        $result = $installer->install();

        $this->assertSame(['copied' => 1, 'backedUp' => 1, 'skipped' => 0], $this->counts($result));
        $this->assertSame('new-content', file_get_contents($this->projectDir . '/src/Kernel.php'));
        $this->assertSame('original-content', file_get_contents($this->projectDir . '/existingFiles/src/Kernel.php.old'));
    }

    // Scaffold files from several installed bundles are all merged into the project
    public function testInstallMergesScaffoldFilesFromEveryInstalledBundle(): void
    {
        $this->addScaffoldBundle('config-bundle', ['templates/a.html.twig' => 'a']);
        $this->addScaffoldBundle('site-bundle', ['templates/b.html.twig' => 'b']);
        $installer = new ScaffoldInstaller($this->projectDir);

        $result = $installer->install();

        $this->assertSame(['copied' => 2, 'backedUp' => 0, 'skipped' => 0], $this->counts($result));
        $this->assertFileExists($this->projectDir . '/templates/a.html.twig');
        $this->assertFileExists($this->projectDir . '/templates/b.html.twig');
    }

    // .gitignore gets an existingFiles/ entry appended, but only once even across repeated installs
    public function testInstallAppendsExistingFilesToGitignoreOnlyOnce(): void
    {
        file_put_contents($this->projectDir . '/.gitignore', "vendor/\n");
        $installer = new ScaffoldInstaller($this->projectDir);

        $installer->install();
        $installer->install();

        $gitignore = file_get_contents($this->projectDir . '/.gitignore');
        $this->assertSame(1, substr_count($gitignore, 'existingFiles/'));
        $this->assertStringContainsString('vendor/', $gitignore);
    }

    // Back-office written content (uploaded medias, site-wide graphics under their role name) gets ignored too, so a deploy resetting the working tree never wipes what production uploaded
    public function testInstallGitignoresBackOfficeWrittenContent(): void
    {
        $installer = new ScaffoldInstaller($this->projectDir);

        $installer->install();

        $gitignore = file_get_contents($this->projectDir . '/.gitignore');
        $this->assertStringContainsString('public/medias', $gitignore);
        foreach (Media::getSingletonRoles() as $role) {
            $this->assertStringContainsString('public/' . $role . '.*', $gitignore);
        }
    }

    // A rule already present (however the site spelled it) is not appended a second time
    public function testInstallDoesNotDuplicateAlreadyPresentRules(): void
    {
        file_put_contents($this->projectDir . '/.gitignore', "public/medias\npublic/favicon.*\n");
        $installer = new ScaffoldInstaller($this->projectDir);

        $installer->install();

        $gitignore = file_get_contents($this->projectDir . '/.gitignore');
        $this->assertSame(1, substr_count($gitignore, 'public/medias'));
        $this->assertSame(1, substr_count($gitignore, 'public/favicon.*'));
    }

    // No vendor/c975l directory at all (e.g. a dry run before composer install): install() must not error out
    public function testInstallHandlesMissingVendorDirectoryGracefully(): void
    {
        $installer = new ScaffoldInstaller($this->projectDir);

        $this->assertSame(['copied' => 0, 'backedUp' => 0, 'skipped' => 0], $this->counts($installer->install()));
    }

    // A target already identical to the scaffold source is left untouched: no backup, no re-copy - re-running install() on an unmodified project must not litter existingFiles/ with no-op backups
    public function testInstallSkipsFileAlreadyIdenticalToScaffoldSource(): void
    {
        $this->addScaffoldBundle('site-bundle', ['src/Kernel.php' => 'same-content']);
        mkdir($this->projectDir . '/src', 0775, true);
        file_put_contents($this->projectDir . '/src/Kernel.php', 'same-content');
        $installer = new ScaffoldInstaller($this->projectDir);

        $result = $installer->install();

        $this->assertSame(['copied' => 0, 'backedUp' => 0, 'skipped' => 1], $this->counts($result));
        $this->assertSame('same-content', file_get_contents($this->projectDir . '/src/Kernel.php'));
        $this->assertDirectoryDoesNotExist($this->projectDir . '/existingFiles');
    }

    // Unlike src/templates/tests/translations, an existing "assets" file is never backed up/overwritten again once it's there, even if the bundle's own copy has since changed - it's the app's own editable file from the first install onward (e.g. a customized assets/styles/themes/site.css)
    public function testInstallNeverOverwritesAnExistingAssetsFileEvenWhenContentDiffers(): void
    {
        $this->addScaffoldBundle('site-bundle', ['assets/styles/themes/site.css' => ':root { --radius-btn: 0; }']);
        mkdir($this->projectDir . '/assets/styles/themes', 0775, true);
        file_put_contents($this->projectDir . '/assets/styles/themes/site.css', ':root { --radius-btn: 999px; }');
        $installer = new ScaffoldInstaller($this->projectDir);

        $result = $installer->install();

        $this->assertSame(['copied' => 0, 'backedUp' => 0, 'skipped' => 1], $this->counts($result));
        $this->assertSame(':root { --radius-btn: 999px; }', file_get_contents($this->projectDir . '/assets/styles/themes/site.css'));
        $this->assertDirectoryDoesNotExist($this->projectDir . '/existingFiles');
    }

    // A migrated site still importing its theme by hand: the provider already contributes it, so the import now fetches a sheet the compiled stylesheet holds - the caller is warned about what is there, not reminded of what is missing
    public function testThemeImportReminderWarnsAboutAStaleAppJsImport(): void
    {
        mkdir($this->projectDir . '/assets/styles/themes', 0775, true);
        file_put_contents($this->projectDir . '/assets/styles/themes/ui.css', ':root {}');
        file_put_contents($this->projectDir . '/assets/app.js', "import './styles/themes/ui.css';\n");
        $this->addThemeProvider();
        $installer = new ScaffoldInstaller($this->projectDir);

        $reminder = $installer->themeImportReminder();

        $this->assertNotNull($reminder);
        $this->assertStringContainsString('Remove the theme import', $reminder);
        $this->assertStringContainsString('ui.css', $reminder);
        // install() never writes to app.js: the removal is the developer's own move
        $this->assertSame("import './styles/themes/ui.css';\n", file_get_contents($this->projectDir . '/assets/app.js'));
    }

    // Same through app.css's @import, the other way a theme used to be wired
    public function testThemeImportReminderWarnsAboutAStaleAppCssImport(): void
    {
        mkdir($this->projectDir . '/assets/styles/themes', 0775, true);
        file_put_contents($this->projectDir . '/assets/styles/themes/site.css', ':root {}');
        file_put_contents($this->projectDir . '/assets/styles/app.css', "@import url(\"./themes/site.css\");\n");
        $this->addThemeProvider();
        $installer = new ScaffoldInstaller($this->projectDir);

        $reminder = (string) $installer->themeImportReminder();

        $this->assertStringContainsString('Remove the theme import', $reminder);
        $this->assertStringContainsString('site.css', $reminder);
    }

    // Without the provider, that import is the only thing loading the theme: advising its removal would take the theme down with it
    public function testThemeImportReminderKeepsTheImportUntilTheProviderIsInstalled(): void
    {
        mkdir($this->projectDir . '/assets/styles/themes', 0775, true);
        file_put_contents($this->projectDir . '/assets/styles/themes/site.css', ':root {}');
        file_put_contents($this->projectDir . '/assets/app.js', "import './styles/themes/site.css';\n");
        $installer = new ScaffoldInstaller($this->projectDir);

        $reminder = (string) $installer->themeImportReminder();

        $this->assertStringContainsString('Keep the theme import', $reminder);
        $this->assertStringContainsString('src/Service/ThemeStylesheetProvider.php', $reminder);
    }

    // The normal state of a site on the provider: theme files present, nothing importing them
    public function testThemeImportReminderIsNullWhenNothingImportsTheThemes(): void
    {
        mkdir($this->projectDir . '/assets/styles/themes', 0775, true);
        file_put_contents($this->projectDir . '/assets/styles/themes/ui.css', ':root {}');
        file_put_contents($this->projectDir . '/assets/styles/themes/site.css', ':root {}');
        file_put_contents($this->projectDir . '/assets/app.js', "import './styles/app.css';\n");
        file_put_contents($this->projectDir . '/assets/styles/app.css', "body { color: red; }\n");
        $installer = new ScaffoldInstaller($this->projectDir);

        $this->assertNull($installer->themeImportReminder());
    }

    // No scaffolded theme yet: nothing to warn about
    public function testThemeImportReminderIsNullWithoutAnyScaffoldedTheme(): void
    {
        $installer = new ScaffoldInstaller($this->projectDir);

        $this->assertNull($installer->themeImportReminder());
    }

    // A theme, but nowhere to import it from (neither app.css nor app.js - e.g. a bundle-only test fixture, or a project not using AssetMapper): no reminder, there would be nothing to act on
    public function testThemeImportReminderIsNullWithoutAnyWiringPoint(): void
    {
        mkdir($this->projectDir . '/assets/styles/themes', 0775, true);
        file_put_contents($this->projectDir . '/assets/styles/themes/ui.css', ':root {}');
        $installer = new ScaffoldInstaller($this->projectDir);

        $this->assertNull($installer->themeImportReminder());
    }

    // Propagating one upgraded scaffold file must not pass over every other file the project may have diverged on
    public function testInstallRestrictedToAPathLeavesTheRestAlone(): void
    {
        $this->addScaffoldBundle('site-bundle', [
            'src/Scheduler/MaintenanceSchedule.php' => 'new-schedule',
            'src/Kernel.php' => 'new-kernel',
        ]);
        mkdir($this->projectDir . '/src', 0775, true);
        file_put_contents($this->projectDir . '/src/Kernel.php', 'my-own-kernel');

        $result = (new ScaffoldInstaller($this->projectDir))->install(['src/Scheduler']);

        $this->assertSame(['copied' => 1, 'backedUp' => 0, 'skipped' => 0], $this->counts($result));
        $this->assertSame('new-schedule', file_get_contents($this->projectDir . '/src/Scheduler/MaintenanceSchedule.php'));
        $this->assertSame('my-own-kernel', file_get_contents($this->projectDir . '/src/Kernel.php'));
    }

    // A path names a directory or a file, and never a mere prefix of either: 'src/Scheduler' must leave src/SchedulerOther alone
    public function testAPathIsNotMatchedAsAPrefix(): void
    {
        $this->addScaffoldBundle('site-bundle', ['src/SchedulerOther/Foo.php' => 'foo']);

        $result = (new ScaffoldInstaller($this->projectDir))->install(['src/Scheduler']);

        $this->assertSame(['copied' => 0, 'backedUp' => 0, 'skipped' => 0], $this->counts($result));
        $this->assertFileDoesNotExist($this->projectDir . '/src/SchedulerOther/Foo.php');
    }

    // A typo, or a path given as it stands in the bundle ('scaffold/src/...'), otherwise reports zero everywhere just like an up-to-date site
    public function testInstallReportsThePathsNoScaffoldFileAnsweredTo(): void
    {
        $this->addScaffoldBundle('site-bundle', ['src/Scheduler/MaintenanceSchedule.php' => 'new-schedule']);

        $result = (new ScaffoldInstaller($this->projectDir))->install(['src/Scheduler', 'src/Sheduler', 'scaffold/src/Scheduler']);

        $this->assertSame(['src/Sheduler', 'scaffold/src/Scheduler'], $result['unmatched']);
    }

    // A path whose every file is already identical did name something: it's a no-op, not a mistake
    public function testAPathMatchingOnlyUpToDateFilesIsNotReportedAsUnmatched(): void
    {
        $this->addScaffoldBundle('site-bundle', ['src/Scheduler/MaintenanceSchedule.php' => 'same-content']);
        mkdir($this->projectDir . '/src/Scheduler', 0775, true);
        file_put_contents($this->projectDir . '/src/Scheduler/MaintenanceSchedule.php', 'same-content');

        $result = (new ScaffoldInstaller($this->projectDir))->install(['src/Scheduler']);

        $this->assertSame(['copied' => 0, 'backedUp' => 0, 'skipped' => 1], $this->counts($result));
        $this->assertSame([], $result['unmatched']);
    }

    // A dry run reports what would happen and writes nothing at all - not the copy, not the backup, not the .gitignore
    public function testDryRunWritesNothing(): void
    {
        $this->addScaffoldBundle('site-bundle', ['src/Kernel.php' => 'new-content']);
        mkdir($this->projectDir . '/src', 0775, true);
        file_put_contents($this->projectDir . '/src/Kernel.php', 'original-content');

        $result = (new ScaffoldInstaller($this->projectDir))->install([], true);

        $this->assertSame(['copied' => 1, 'backedUp' => 1, 'skipped' => 0], $this->counts($result));
        $this->assertSame(['src/Kernel.php'], $result['files']);
        $this->assertSame('original-content', file_get_contents($this->projectDir . '/src/Kernel.php'));
        $this->assertFileDoesNotExist($this->projectDir . '/existingFiles/src/Kernel.php.old');
        $this->assertFileDoesNotExist($this->projectDir . '/.gitignore');
    }
}
