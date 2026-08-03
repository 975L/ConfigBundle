<?php

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

class UserRepositoryTest extends TestCase
{
    private function createRepository(EntityManagerInterface $entityManager): UserRepository
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnMap([
            [User::class, $entityManager],
        ]);

        return new UserRepository($registry);
    }

    public function testUpgradePasswordThrowsForUnsupportedUser(): void
    {
        // The exception is thrown before the entity manager is ever touched, so an empty registry stub is enough here.
        $repository = new UserRepository($this->createStub(ManagerRegistry::class));

        $unsupportedUser = $this->createStub(PasswordAuthenticatedUserInterface::class);

        $this->expectException(UnsupportedUserException::class);
        $repository->upgradePassword($unsupportedUser, 'newHashedPassword');
    }

    public function testUpgradePasswordSetsPasswordAndFlushes(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturnMap([
            [User::class, new ClassMetadata(User::class)],
        ]);
        $repository = $this->createRepository($entityManager);

        $user = new User();

        $entityManager->expects($this->once())->method('persist')->with($user);
        $entityManager->expects($this->once())->method('flush');

        $repository->upgradePassword($user, 'newHashedPassword');

        $this->assertSame('newHashedPassword', $user->getPassword());
    }
}
