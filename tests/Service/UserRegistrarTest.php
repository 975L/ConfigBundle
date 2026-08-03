<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\EmailVerifier;
use c975L\ConfigBundle\Service\UserRegistrar;
use c975L\ConfigBundle\Tests\Fixtures\UserStub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserRegistrarTest extends TestCase
{
    public function testRegisterHashesPersistsAndSendsConfirmation(): void
    {
        $user = new UserStub('new-user@example.test');

        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($user);
        $entityManager->expects($this->once())->method('flush');

        $emailVerifier = $this->createMock(EmailVerifier::class);
        $emailVerifier->expects($this->once())
            ->method('sendEmailConfirmation')
            ->with('app_verify_email', $user, 'Confirm your email', 'new-user@example.test')
            ->willReturn(true)
        ;

        $registrar = new UserRegistrar($passwordHasher, $entityManager, $emailVerifier);
        $result = $registrar->register($user, 'Str0ngPassword!', 'app_verify_email', 'Confirm your email', 'new-user@example.test');

        $this->assertTrue($result);
        $this->assertSame('hashed-password', $user->getPassword());
        $this->assertNotNull($user->getCreation());
        $this->assertNotNull($user->getModification());
    }
}
