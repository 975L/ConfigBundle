<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use App\Entity\User;
use c975L\ConfigBundle\Contract\UserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

// Creates the bootstrap account a fresh install needs to reach its own back-office - shared by c975l:config:user-create and SiteBundle's c975l:site:create wizard, so both produce exactly the same account instead of one of them drifting. Builds App\Entity\User directly (this bundle's own scaffold ships it, see scaffold/src/Entity/User.php), the concrete class being what Doctrine persists
class AdminUserCreator
{
    // Every role the back-office knows: this account is the site's owner (producer or self-hoster). ROLE_SUPER_ADMIN is the only one allowed to see/edit the "backup" config group (DB credentials, see ConfigCrudController), and ROLE_EDITOR has to be granted explicitly since no role_hierarchy is shipped - ROLE_ADMIN alone wouldn't pass the "site-role-editor" gated actions
    public const OWNER_ROLES = ['ROLE_EDITOR', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function exists(string $email): bool
    {
        return null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    // Returned as the Contract\UserInterface every c975L entity relates to, not as App\Entity\User: a bundle calling this can then hold the result without the app-space class having to be autoloadable from its own checkout. Verified and enabled up front: there is no email to confirm for an account created from the console, and an admin locked out of the site they just installed would have nothing to unlock it with
    public function create(string $email, string $plainPassword, array $roles = self::OWNER_ROLES): UserInterface
    {
        $now = new \DateTime();

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setRoles($roles);
        $user->setIsVerified(true);
        $user->setIsEnabled(true);
        $user->setCreation($now);
        $user->setModification($now);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
