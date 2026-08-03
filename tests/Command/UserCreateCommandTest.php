<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Command;

use c975L\ConfigBundle\Command\UserCreateCommand;
use c975L\ConfigBundle\Service\AdminUserCreator;
use c975L\ConfigBundle\Service\UserFormSeeder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UserCreateCommandTest extends TestCase
{
    private function createCommand(
        ?AdminUserCreator $adminUserCreator = null,
        ?UserFormSeeder $userFormSeeder = null,
    ): UserCreateCommand {
        return new UserCreateCommand(
            $adminUserCreator ?? $this->createStub(AdminUserCreator::class),
            $userFormSeeder ?? $this->createStub(UserFormSeeder::class),
        );
    }

    public function testConfigureHasTheEmailAndPasswordOptions(): void
    {
        $definition = $this->createCommand()->getDefinition();

        $this->assertTrue($definition->hasOption('email'));
        $this->assertTrue($definition->hasOption('password'));
    }

    // The account and the forms around it come as one: an account without a login/reset flow is unusable on an app that has no site foundation to seed them
    public function testItCreatesTheAccountAndSeedsTheAccountForms(): void
    {
        $adminUserCreator = $this->createMock(AdminUserCreator::class);
        $adminUserCreator->method('exists')->willReturn(false);
        $adminUserCreator->expects($this->once())->method('create')->with('admin@example.com', 'password123');

        $userFormSeeder = $this->createMock(UserFormSeeder::class);
        $userFormSeeder->expects($this->once())->method('ensureAll');

        $tester = new CommandTester($this->createCommand($adminUserCreator, $userFormSeeder));
        $exitCode = $tester->execute(['--email' => 'admin@example.com', '--password' => 'password123']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('admin@example.com', $tester->getDisplay());
    }

    // Re-running the command on an app that already has that account is a no-op, not a failure - it's the bootstrap command, it gets run twice
    public function testItSkipsCreationWhenTheEmailIsAlreadyTaken(): void
    {
        $adminUserCreator = $this->createMock(AdminUserCreator::class);
        $adminUserCreator->method('exists')->willReturn(true);
        $adminUserCreator->expects($this->never())->method('create');

        $userFormSeeder = $this->createMock(UserFormSeeder::class);
        $userFormSeeder->expects($this->never())->method('ensureAll');

        $tester = new CommandTester($this->createCommand($adminUserCreator, $userFormSeeder));
        $exitCode = $tester->execute(['--email' => 'admin@example.com', '--password' => 'password123']);

        $this->assertSame(0, $exitCode);
    }

    public function testItFailsOnAnInvalidEmail(): void
    {
        $adminUserCreator = $this->createMock(AdminUserCreator::class);
        $adminUserCreator->expects($this->never())->method('create');

        $tester = new CommandTester($this->createCommand($adminUserCreator));
        $exitCode = $tester->execute(['--email' => 'not-an-email', '--password' => 'password123']);

        $this->assertSame(1, $exitCode);
    }

    public function testItFailsOnATooShortPassword(): void
    {
        $adminUserCreator = $this->createMock(AdminUserCreator::class);
        $adminUserCreator->method('exists')->willReturn(false);
        $adminUserCreator->expects($this->never())->method('create');

        $tester = new CommandTester($this->createCommand($adminUserCreator));
        $exitCode = $tester->execute(['--email' => 'admin@example.com', '--password' => 'short']);

        $this->assertSame(1, $exitCode);
    }
}
