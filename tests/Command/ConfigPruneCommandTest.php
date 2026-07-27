<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\ConfigPruneCommand;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigDeclarationLocator;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

class ConfigPruneCommandTest extends TestCase
{
    private string $projectDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/c975l-config-prune-' . uniqid();
        $this->filesystem->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    private function declareSlugs(array $slugs): void
    {
        $dir = $this->projectDir . '/vendor/c975l/site-bundle/config';
        $this->filesystem->mkdir($dir);
        $this->filesystem->dumpFile($dir . '/configs.json', json_encode(array_map(fn ($slug) => ['slug' => $slug], $slugs)));
    }

    private function createTester(
        array $slugsInDatabase,
        ?EntityManagerInterface $manager = null,
        ?ConfigServiceInterface $configService = null,
    ): CommandTester {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findAllSlugs')->willReturn($slugsInDatabase);
        $repository->method('findBy')->willReturnCallback(
            fn (array $criteria) => array_map(fn ($slug) => (new Config())->setSlug($slug)->setLabel($slug), $criteria['slug'])
        );

        return new CommandTester(new ConfigPruneCommand(
            $repository,
            new ConfigDeclarationLocator($this->projectDir),
            $configService ?? $this->createStub(ConfigServiceInterface::class),
            $manager ?? $this->createStub(EntityManagerInterface::class),
        ));
    }

    // An empty vendor/ would make every entry look undeclared: the command must refuse rather than wipe the whole table
    public function testExecuteFailsWhenNoDeclarationFileExists(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('remove');

        $tester = $this->createTester(['site-name'], $manager);
        $tester->execute(['--force' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No configs*.json found', $tester->getDisplay());
    }

    // A malformed configs.json declares none of its slugs, which would then all be taken for orphans and deleted, values included, by --force
    public function testExecuteFailsWhenADeclarationFileCannotBeParsed(): void
    {
        $this->declareSlugs(['site-name']);
        $this->filesystem->mkdir($this->projectDir . '/config');
        $this->filesystem->dumpFile($this->projectDir . '/config/configs.json', '{"broken": ');

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('remove');
        $manager->expects($this->never())->method('flush');

        $tester = $this->createTester(['site-name', 'site-favicon'], $manager);
        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unreadable configs*.json', $tester->getDisplay());
    }

    public function testExecuteReportsWhenEveryEntryIsDeclared(): void
    {
        $this->declareSlugs(['site-name', 'site-url']);

        $tester = $this->createTester(['site-name', 'site-url']);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No orphan config entry', $tester->getDisplay());
    }

    public function testExecuteListsOrphansWithoutDeletingThemByDefault(): void
    {
        $this->declareSlugs(['site-name']);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('remove');
        $manager->expects($this->never())->method('flush');

        $tester = $this->createTester(['site-name', 'site-favicon', 'site-logo'], $manager);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('2 config entrie(s) no longer declared', $tester->getDisplay());
        $this->assertStringContainsString('site-favicon', $tester->getDisplay());
        $this->assertStringContainsString('site-logo', $tester->getDisplay());
        $this->assertStringContainsString('Re-run with --force', $tester->getDisplay());
    }

    public function testExecuteDeletesOrphansWithForceAndConfirmation(): void
    {
        $this->declareSlugs(['site-name']);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('remove');
        $manager->expects($this->once())->method('flush');

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('invalidateCache');

        $tester = $this->createTester(['site-name', 'site-favicon'], $manager, $configService);
        $tester->setInputs(['yes']);
        $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('1 entrie(s) deleted', $tester->getDisplay());
    }

    public function testExecuteKeepsOrphansWhenConfirmationIsDeclined(): void
    {
        $this->declareSlugs(['site-name']);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('remove');
        $manager->expects($this->never())->method('flush');

        $tester = $this->createTester(['site-name', 'site-favicon'], $manager);
        $tester->setInputs(['no']);
        $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Aborted, nothing deleted', $tester->getDisplay());
    }

    public function testExecuteDeletesWithoutConfirmationWhenNonInteractive(): void
    {
        $this->declareSlugs(['site-name']);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('flush');

        $tester = $this->createTester(['site-name', 'site-favicon'], $manager);
        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertStringContainsString('1 entrie(s) deleted', $tester->getDisplay());
    }

    // The application's own config/configs.json declares entries too, and must not be seen as orphaned
    public function testExecuteConsidersTheApplicationOwnDeclarations(): void
    {
        $this->declareSlugs(['site-name']);
        $this->filesystem->mkdir($this->projectDir . '/config');
        $this->filesystem->dumpFile($this->projectDir . '/config/configs.json', json_encode([['slug' => 'ai-help-llm-api-key']]));

        $tester = $this->createTester(['site-name', 'ai-help-llm-api-key']);
        $tester->execute([]);

        $this->assertStringContainsString('No orphan config entry', $tester->getDisplay());
    }
}
