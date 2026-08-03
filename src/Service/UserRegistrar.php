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
use Symfony\Component\Security\Core\User\UserInterface;

// Hashes+persists a freshly built user and sends its verification email - the "what happens once a registration form is valid" step, extracted out of the app-copied scaffold's RegistrationController (see UPGRADE.md) so it's shared, tested bundle code. The caller (still in scaffold, App\Entity\User being app-space) builds $user itself from the submitted "register" c975L\UiBundle\Entity\Form data; every other concern (route, honeypot, rate-limiting, email uniqueness, CGU/GDPR checkboxes) deliberately stays there, unrelated to what happens on success.
class UserRegistrar
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailVerifier $emailVerifier,
    ) {
    }

    public function register(PasswordAuthenticatedUserInterface & UserInterface $user, string $plainPassword, string $verifyEmailRouteName, string $subject, string $to): bool
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $now = new \DateTime();
        if (method_exists($user, 'setCreation')) {
            $user->setCreation($now);
        }
        if (method_exists($user, 'setModification')) {
            $user->setModification($now);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->emailVerifier->sendEmailConfirmation($verifyEmailRouteName, $user, $subject, $to);
    }
}
