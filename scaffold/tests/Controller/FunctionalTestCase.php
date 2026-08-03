<?php

namespace App\Tests\Controller;

use App\Entity\User;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

// If "site-maintenance" is enabled (real dev/prod config), anonymous visitors get a 503 on every front page. Logging in as an admin bypasses it, same as a real admin would, so tests relying on this base class stay true regardless of that toggle.
abstract class FunctionalTestCase extends WebTestCase
{
    // Exposed so a test can call $client->loginUser($this->authenticatedUser) again mid-scenario, after a route with a session side effect (e.g. app_logout) runs in the same test.
    protected ?User $authenticatedUser = null;

    // The user needs a real id (EntityUserProvider::refreshUser() requires one to reload it from the session on the next request), so it's persisted - dama/doctrine-test-bundle rolls the whole test's transaction back, it never actually reaches the dev database.
    // $extraRoles adds to the admin role rather than replacing it: a screen reserved to ROLE_SUPER_ADMIN isn't even rendered for a plain admin, so a test covering those needs to ask for it (see ManagementSmokeTest).
    protected function createAuthenticatedClient(array $extraRoles = []): KernelBrowser
    {
        $client = static::createClient();

        $container = static::getContainer();
        $adminRole = $container->get(ConfigServiceInterface::class)->get('site-role-admin');

        $user = (new User())
            ->setEmail('functional-tests@example.test')
            ->setPassword('not-used')
            ->setRoles(array_values(array_unique([$adminRole, ...$extraRoles])))
            ->setIsEnabled(true)
            ->setIsVerified(true)
            ->setCreation(new \DateTime())
            ->setModification(new \DateTime());

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        $this->authenticatedUser = $user;
        $client->loginUser($user);

        return $client;
    }
}
