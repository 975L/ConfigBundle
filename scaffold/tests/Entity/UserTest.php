<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $user = new User();

        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testGetRolesDoesNotDuplicateRoleUser(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN', 'ROLE_USER']);

        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], array_values($user->getRoles()));
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $user = (new User())->setEmail('user@example.test');

        $this->assertSame('user@example.test', $user->getUserIdentifier());
    }

    public function testGetUserIdentifierReturnsEmptyStringWhenEmailIsNull(): void
    {
        $this->assertSame('', (new User())->getUserIdentifier());
    }

    public function testSerializeHashesPasswordInsteadOfStoringItInClear(): void
    {
        $user = (new User())->setPassword('$argon2id$hashedPassword');

        $data = $user->__serialize();

        $this->assertSame(hash('crc32c', '$argon2id$hashedPassword'), $data["\0App\\Entity\\User\0password"]);
        $this->assertStringNotContainsString('$argon2id$hashedPassword', (string) $data["\0App\\Entity\\User\0password"]);
    }

    public function testFluentSettersReturnSameInstance(): void
    {
        $user = new User();

        $this->assertSame($user, $user->setEmail('a@b.c'));
        $this->assertSame($user, $user->setPassword('pwd'));
        $this->assertSame($user, $user->setRoles([]));
        $this->assertSame($user, $user->setIsVerified(true));
        $this->assertSame($user, $user->setIsEnabled(true));
        $this->assertSame($user, $user->setCreation(new \DateTime()));
        $this->assertSame($user, $user->setModification(new \DateTime()));
    }

    public function testDefaultsAreNotVerifiedAndNotEnabled(): void
    {
        $user = new User();

        $this->assertFalse($user->isVerified());
        $this->assertFalse($user->isEnabled());
    }
}
