<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Security\Voter;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

// Decides who may act on a given User row in the backoffice - UserCrudController hands it to EasyAdmin as the entity permission, which evaluates it per row: on the index (an inaccessible row keeps its place, minus its actions) and again before the edit/delete page is built at all. One rule covering every action, including the ones added later, rather than one guard per action
class UserManagementVoter extends Voter
{
    public const MANAGE = 'C975L_MANAGE_USER';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute;
    }

    // A super admin's account is only manageable by another super admin. Freezing the "roles" field (see UserCrudController) closes the demotion path alone: a plain admin could still change a super admin's email and have a password reset sent to their own mailbox, or simply delete the account
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // Checked before site-role-admin, not after: no role_hierarchy is shipped, so ROLE_SUPER_ADMIN doesn't imply it on its own - an account holding only the highest role would otherwise be refused every row of the very screen that could grant it the one it's missing
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        if (!$this->security->isGranted((string) $this->configService->get('site-role-admin'))) {
            return false;
        }

        return !$subject instanceof UserInterface || !\in_array('ROLE_SUPER_ADMIN', $subject->getRoles(), true);
    }
}
