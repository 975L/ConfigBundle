<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\CheckDeprecationsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

class CheckDeprecationsCommandTest extends TestCase
{
    private string $projectDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/c975l-check-deprecations-' . uniqid();
        $this->filesystem->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    private function writeDeprecationsLog(array $messages, string $env = 'dev'): void
    {
        $lines = [];
        foreach ($messages as $message) {
            $lines[] = sprintf('[2026-07-17T10:00:00+00:00] deprecation.WARNING: %s {"exception":"[object] (...)"}', $message);
        }
        $this->filesystem->mkdir($this->projectDir . '/var/log');
        $this->filesystem->dumpFile($this->projectDir . "/var/log/{$env}.deprecations.log", implode("\n", $lines) . "\n");
    }

    private function createTester(): CommandTester
    {
        return new CommandTester(new CheckDeprecationsCommand($this->projectDir, 'dev'));
    }

    public function testExecuteWarnsWhenLogFileIsMissing(): void
    {
        $tester = $this->createTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Fichier introuvable', $tester->getDisplay());
    }

    public function testExecuteSucceedsWhenLogFileIsEmpty(): void
    {
        $this->writeDeprecationsLog([]);

        $tester = $this->createTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Aucune dépréciation trouvée', $tester->getDisplay());
    }

    public function testExecuteReportsExactFqcnMatchAsActionnable(): void
    {
        $this->filesystem->mkdir($this->projectDir . '/src');
        $this->filesystem->dumpFile(
            $this->projectDir . '/src/Foo.php',
            '<?php use App\Deprecated\ThingDoer;'
        );
        $this->writeDeprecationsLog(['Class "App\Deprecated\ThingDoer" is deprecated, use something else instead.']);

        $tester = $this->createTester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('ACTIONNABLE', $display);
        $this->assertStringContainsString('app -> src/Foo.php', $display);
        $this->assertStringNotContainsString('À vérifier', $display);
    }

    public function testExecuteReportsNamespaceOnlyMatchAsPossibleHit(): void
    {
        $this->filesystem->mkdir($this->projectDir . '/src');
        $this->filesystem->dumpFile(
            $this->projectDir . '/src/Foo.php',
            '<?php use App\Deprecated\UnrelatedSibling;'
        );
        $this->writeDeprecationsLog(['Class "App\Deprecated\ThingDoer" is deprecated, use something else instead.']);

        $tester = $this->createTester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('ACTIONNABLE', $display);
        $this->assertStringContainsString('À vérifier', $display);
        $this->assertStringContainsString('app -> src/Foo.php', $display);
    }

    public function testExecuteDoesNotReportPossibleHitWhenFileAlreadyHasExactHit(): void
    {
        $this->filesystem->mkdir($this->projectDir . '/src');
        $this->filesystem->dumpFile(
            $this->projectDir . '/src/Foo.php',
            '<?php use App\Deprecated\ThingDoer;'
        );
        $this->writeDeprecationsLog(['Class "App\Deprecated\ThingDoer" is deprecated, use something else instead.']);

        $tester = $this->createTester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('ACTIONNABLE', $display);
        $this->assertStringNotContainsString('À vérifier', $display);
    }

    // Nothing traces back to app/c975L source - it's surely the sole concern of the third-party package that logged it, so it's dropped from the report entirely instead of just being noted
    public function testExecuteSkipsMessagesWithNoAppOrC975lMatch(): void
    {
        $this->writeDeprecationsLog(['Class "Some\Vendor\Thing" is deprecated, use something else instead.']);

        $tester = $this->createTester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('ACTIONNABLE', $display);
        $this->assertStringNotContainsString('À vérifier', $display);
        $this->assertStringContainsString('Aucune dépréciation actionnable trouvée', $display);
    }

    public function testExecuteOmitsThirdPartyMessageWhileKeepingActionableOne(): void
    {
        $this->filesystem->mkdir($this->projectDir . '/src');
        $this->filesystem->dumpFile(
            $this->projectDir . '/src/Foo.php',
            '<?php use App\Deprecated\ThingDoer;'
        );
        $this->writeDeprecationsLog([
            'Class "App\Deprecated\ThingDoer" is deprecated, use something else instead.',
            'Class "Some\Vendor\Thing" is deprecated, use something else instead.',
        ]);

        $tester = $this->createTester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('ThingDoer', $display);
        $this->assertStringNotContainsString('Some\Vendor\Thing', $display);
        $this->assertStringContainsString('1 dépréciation(s) actionnable(s), 1 occurrence(s) au total.', $display);
    }

    public function testExecuteReadsLogFromGivenEnvironmentArgument(): void
    {
        $this->filesystem->mkdir($this->projectDir . '/src');
        $this->filesystem->dumpFile(
            $this->projectDir . '/src/Foo.php',
            '<?php use App\Deprecated\ThingDoer;'
        );
        $this->writeDeprecationsLog(['Class "App\Deprecated\ThingDoer" is deprecated.'], 'test');

        $tester = $this->createTester();
        $tester->execute(['env' => 'test']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('1 dépréciation(s) actionnable(s), 1 occurrence(s) au total.', $tester->getDisplay());
    }
}
