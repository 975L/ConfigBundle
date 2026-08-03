<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Security\Voter;

use App\Entity\User;
use c975L\ConfigBundle\Security\Voter\UserManagementVoter;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class UserManagementVoterTest extends TestCase
{
    private function createVoter(bool $isAdmin, bool $isSuperAdmin): UserManagementVoter
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_ADMIN');

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => 'ROLE_SUPER_ADMIN' === $attribute ? $isSuperAdmin : $isAdmin,
        );

        return new UserManagementVoter($configService, $security);
    }

    private function createUser(array $roles): User
    {
        $user = new User();
        $user->setRoles($roles);

        return $user;
    }

    private function vote(UserManagementVoter $voter, mixed $subject, string $attribute = UserManagementVoter::MANAGE): int
    {
        return $voter->vote($this->createStub(TokenInterface::class), $subject, [$attribute]);
    }

    // Anything but the backoffice's own permission is somebody else's business
    public function testVoterAbstainsOnAnotherAttribute(): void
    {
        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote($this->createVoter(true, true), $this->createUser(['ROLE_ADMIN']), 'ROLE_ADMIN'),
        );
    }

    // The role the "site-role-admin" config names is still the entry ticket, exactly as the plain role permission this replaced
    public function testAUserWithoutTheAdminRoleIsDenied(): void
    {
        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->createVoter(false, false), $this->createUser(['ROLE_EDITOR'])),
        );
    }

    // No role_hierarchy is shipped, so ROLE_SUPER_ADMIN doesn't imply the "site-role-admin" role on its own - an account holding only the highest role must still get through, or it's locked out of the very screen that could grant it the missing one
    public function testASuperAdminWithoutTheAdminRoleIsStillGranted(): void
    {
        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->createVoter(false, true), $this->createUser(['ROLE_SUPER_ADMIN'])),
        );
    }

    public function testAnAdminMayManageAnOrdinaryUser(): void
    {
        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->createVoter(true, false), $this->createUser(['ROLE_ADMIN'])),
        );
    }

    // The whole point: freezing the roles field alone left the email (change it, then have a password reset sent to your own mailbox) and plain deletion open
    public function testALesserAdminMayNotManageASuperAdmin(): void
    {
        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->createVoter(true, false), $this->createUser(['ROLE_SUPER_ADMIN'])),
        );
    }

    public function testASuperAdminMayManageASuperAdmin(): void
    {
        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->createVoter(true, true), $this->createUser(['ROLE_SUPER_ADMIN'])),
        );
    }

    // EasyAdmin checks the entity permission on the index page too, where no instance is involved yet - the role alone decides there
    public function testTheRoleAloneDecidesWithoutAUserInstance(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->createVoter(true, false), null));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->createVoter(false, false), null));
    }
}
