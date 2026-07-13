<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\EncryptSensitiveCommand;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\VaultEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class EncryptSensitiveCommandTest extends TestCase
{
    private function createConfig(string $slug, ?string $value, bool $isSensitive = true): Config
    {
        return (new Config())->setSlug($slug)->setLabel($slug)->setIsSensitive($isSensitive)->setValue($value);
    }

    private function createRepository(array $configs): ConfigRepository
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('findAll')->willReturn($configs);

        return $repository;
    }

    private function createTester(
        ConfigRepository $repository,
        VaultEncryptor $vaultEncryptor,
        EntityManagerInterface $manager,
    ): CommandTester {
        return new CommandTester(new EncryptSensitiveCommand($repository, $vaultEncryptor, $manager));
    }

    public function testExecuteFailsWhenVaultKeyIsNotDefined(): void
    {
        $tester = $this->createTester(
            $this->createRepository([]),
            new VaultEncryptor(null),
            $this->createStub(EntityManagerInterface::class),
        );
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('C975L_VAULT_KEY is not defined', $tester->getDisplay());
    }

    public function testExecuteReportsWhenNoSensitiveConfigExists(): void
    {
        $configs = [$this->createConfig('site-name', 'My Site', isSensitive: false)];

        $tester = $this->createTester(
            $this->createRepository($configs),
            new VaultEncryptor('a-test-vault-key'),
            $this->createStub(EntityManagerInterface::class),
        );
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No sensitive config found', $tester->getDisplay());
    }

    public function testExecuteEncryptsPlainTextSensitiveValuesAndFlushes(): void
    {
        $config = $this->createConfig('api-key', 'plain-secret');

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('persist')->with($config);
        $manager->expects($this->once())->method('flush');

        $vaultEncryptor = new VaultEncryptor('a-test-vault-key');
        $tester = $this->createTester($this->createRepository([$config]), $vaultEncryptor, $manager);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('plain-secret', $vaultEncryptor->decrypt($config->getValue()));
        $this->assertStringContainsString('1 encrypted, 0 skipped', $tester->getDisplay());
    }

    public function testExecuteSkipsAlreadyEncryptedValues(): void
    {
        $vaultEncryptor = new VaultEncryptor('a-test-vault-key');
        $config = $this->createConfig('api-key', $vaultEncryptor->encrypt('already-encrypted'));

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('persist');
        $manager->expects($this->once())->method('flush');

        $tester = $this->createTester($this->createRepository([$config]), $vaultEncryptor, $manager);
        $tester->execute([]);

        $this->assertStringContainsString('0 encrypted, 1 skipped', $tester->getDisplay());
    }

    public function testExecuteSkipsEmptyValues(): void
    {
        $config = $this->createConfig('api-key', null);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('persist');
        $manager->expects($this->once())->method('flush');

        $tester = $this->createTester(
            $this->createRepository([$config]),
            new VaultEncryptor('a-test-vault-key'),
            $manager,
        );
        $tester->execute([]);

        $this->assertStringContainsString('0 encrypted, 1 skipped', $tester->getDisplay());
    }

    public function testExecuteIgnoresNonSensitiveConfigsEntirely(): void
    {
        $sensitive = $this->createConfig('api-key', 'secret', isSensitive: true);
        $nonSensitive = $this->createConfig('site-name', 'My Site', isSensitive: false);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('persist')->with($sensitive);

        $tester = $this->createTester(
            $this->createRepository([$sensitive, $nonSensitive]),
            new VaultEncryptor('a-test-vault-key'),
            $manager,
        );
        $tester->execute([]);

        $this->assertStringContainsString('1 encrypted, 0 skipped', $tester->getDisplay());
    }
}
