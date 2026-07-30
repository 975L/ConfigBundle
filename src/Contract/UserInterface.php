<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Contract;

use Symfony\Component\Security\Core\User\UserInterface as SecurityUserInterface;

// The application's user entity as the c975L bundles see it: they relate to it (Config::$user, Page::$user, Block::$user...) without being able to reference App\Entity\User, which lives in app-space. c975LConfigBundle::prependExtension() maps this interface onto App\Entity\User through Doctrine's resolve_target_entities, so the applications have nothing to declare
interface UserInterface extends SecurityUserInterface
{
    // Doctrine assigns it by reflection, hence the nullable return - int for an auto-increment identifier, string for a uuid. Not part of any Symfony interface, though every bundle relating to a user needs it
    public function getId(): int | string | null;
}
