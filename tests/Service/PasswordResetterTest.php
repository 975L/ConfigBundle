<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\PasswordResetter;
use c975L\ConfigBundle\Tests\Fixtures\UserStub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PasswordResetterTest extends TestCase
{
    public function testResetPasswordHashesAndFlushes(): void
    {
        $user = new UserStub('user@example.test');

        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('new-hashed-password');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $resetter = new PasswordResetter($passwordHasher, $entityManager);
        $resetter->resetPassword($user, 'NewStr0ngPassword!');

        $this->assertSame('new-hashed-password', $user->getPassword());
        $this->assertNotNull($user->getModification());
    }
}
