<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserInterface;

class UserCheckerTest extends TestCase
{
    public function testCheckPreAuthThrowsWhenUserIsNotEnabled(): void
    {
        $user = (new User())->setIsEnabled(false);

        $this->expectException(DisabledException::class);
        (new UserChecker())->checkPreAuth($user);
    }

    public function testCheckPreAuthDoesNotThrowWhenUserIsEnabled(): void
    {
        $user = (new User())->setIsEnabled(true);

        (new UserChecker())->checkPreAuth($user);

        $this->addToAssertionCount(1);
    }

    public function testCheckPreAuthIgnoresUsersNotInstanceOfAppUser(): void
    {
        $user = $this->createStub(UserInterface::class);

        (new UserChecker())->checkPreAuth($user);

        $this->addToAssertionCount(1);
    }

    public function testCheckPostAuthDoesNothing(): void
    {
        $user = (new User())->setIsEnabled(false);

        (new UserChecker())->checkPostAuth($user);

        $this->addToAssertionCount(1);
    }
}
