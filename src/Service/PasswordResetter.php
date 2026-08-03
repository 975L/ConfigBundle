<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

// Hashes the new password and persists it - the "what happens once the change-password form is valid" step, extracted out of the app-copied scaffold's ResetPasswordController (see UPGRADE.md). Token generation/validation (SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface) stays called directly from the controller: it's a thin pass-through already, wrapping it here would add indirection without extracting any real logic.
class PasswordResetter
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resetPassword(PasswordAuthenticatedUserInterface $user, string $plainPassword): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        if (method_exists($user, 'setModification')) {
            $user->setModification(new \DateTime());
        }

        $this->entityManager->flush();
    }
}
