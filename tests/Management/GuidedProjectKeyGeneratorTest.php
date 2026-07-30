<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\GuidedProjectKeyGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class GuidedProjectKeyGeneratorTest extends TestCase
{
    private function createGenerator(?string $userIdentifier, string $secret = 'app-secret'): GuidedProjectKeyGenerator
    {
        $security = $this->createStub(Security::class);

        if (null === $userIdentifier) {
            $security->method('getUser')->willReturn(null);
        } else {
            $user = $this->createStub(UserInterface::class);
            $user->method('getUserIdentifier')->willReturn($userIdentifier);
            $security->method('getUser')->willReturn($user);
        }

        return new GuidedProjectKeyGenerator($security, $secret);
    }

    public function testGetKeyReturnsAnEmptyStringWithoutALoggedInUser(): void
    {
        $this->assertSame('', $this->createGenerator(null)->getKey());
    }

    public function testGetKeyReturnsSixteenHexChars(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $this->createGenerator('admin@example.com')->getKey());
    }

    // The whole point of the key: the same admin gets the same storage back on every page, two admins sharing one browser profile never share theirs
    public function testGetKeyIsStableForTheSameUser(): void
    {
        $this->assertSame(
            $this->createGenerator('admin@example.com')->getKey(),
            $this->createGenerator('admin@example.com')->getKey(),
        );
    }

    public function testGetKeyDiffersBetweenUsers(): void
    {
        $this->assertNotSame(
            $this->createGenerator('admin@example.com')->getKey(),
            $this->createGenerator('other@example.com')->getKey(),
        );
    }

    // A localStorage key outlives the session, so the identifier itself - an email, most of the time - must never end up in it
    public function testGetKeyLeaksNothingOfTheIdentifier(): void
    {
        $this->assertStringNotContainsString('admin', $this->createGenerator('admin@example.com')->getKey());
    }

    // Keyed with the application secret rather than plainly hashed: an email lives in a space small enough to be brute-forced back from an unsalted digest
    public function testGetKeyIsKeyedWithTheApplicationSecret(): void
    {
        $this->assertNotSame(
            $this->createGenerator('admin@example.com', 'one-secret')->getKey(),
            $this->createGenerator('admin@example.com', 'another-secret')->getKey(),
        );
    }
}
