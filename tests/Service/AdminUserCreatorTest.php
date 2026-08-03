<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use App\Entity\User;
use c975L\ConfigBundle\Service\AdminUserCreator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminUserCreatorTest extends TestCase
{
    private function createEntityManager(?User $existing, ?array &$persisted = null): EntityManagerInterface
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($existing);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        return $entityManager;
    }

    private function createPasswordHasher(): UserPasswordHasherInterface
    {
        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        return $passwordHasher;
    }

    public function testExistsReportsWhetherAnAccountAlreadyCarriesThatEmail(): void
    {
        $this->assertTrue((new AdminUserCreator($this->createEntityManager(new User()), $this->createPasswordHasher()))->exists('admin@example.test'));
        $this->assertFalse((new AdminUserCreator($this->createEntityManager(null), $this->createPasswordHasher()))->exists('admin@example.test'));
    }

    // Verified and enabled up front: there is no email to confirm for an account created from the console, and an admin locked out of the site they just installed would have nothing to unlock it with
    public function testCreatePersistsAHashedVerifiedAndEnabledOwnerAccount(): void
    {
        $persisted = [];
        $creator = new AdminUserCreator($this->createEntityManager(null, $persisted), $this->createPasswordHasher());

        $user = $creator->create('admin@example.test', 'secret1234');

        $this->assertSame([$user], $persisted);
        $this->assertSame('admin@example.test', $user->getEmail());
        $this->assertSame('hashed-password', $user->getPassword());
        $this->assertTrue($user->isVerified());
        $this->assertTrue($user->isEnabled());
    }

    // Every back-office role, ROLE_EDITOR included: no role_hierarchy is shipped, so ROLE_ADMIN alone wouldn't pass the "site-role-editor" gated actions. ROLE_USER is appended by User::getRoles() itself
    public function testCreateGrantsEveryOwnerRoleByDefault(): void
    {
        $creator = new AdminUserCreator($this->createEntityManager(null), $this->createPasswordHasher());

        $this->assertSame(['ROLE_EDITOR', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_USER'], $creator->create('admin@example.test', 'secret1234')->getRoles());
    }

    // A caller adding a plain member rather than an owner passes its own set
    public function testCreateAcceptsAnExplicitRoleSet(): void
    {
        $creator = new AdminUserCreator($this->createEntityManager(null), $this->createPasswordHasher());

        $this->assertSame(['ROLE_EDITOR', 'ROLE_USER'], $creator->create('editor@example.test', 'secret1234', ['ROLE_EDITOR'])->getRoles());
    }
}
