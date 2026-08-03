<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Management\RedirectImportProvider;
use c975L\ConfigBundle\Repository\RedirectRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class RedirectImportProviderTest extends TestCase
{
    private function createRedirectRepository(?Redirect $existingRedirect = null): RedirectRepository
    {
        $repository = $this->createStub(RedirectRepository::class);
        $repository->method('findOneByFromPath')->willReturn($existingRedirect);

        return $repository;
    }

    public function testSupportsImportOnlyMatchesSiteRedirectKind(): void
    {
        $provider = new RedirectImportProvider($this->createStub(EntityManagerInterface::class), $this->createRedirectRepository());

        $this->assertTrue($provider->supportsImport('site_redirect'));
        $this->assertFalse($provider->supportsImport('site_page'));
    }

    public function testImportCreatesANewRedirect(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new RedirectImportProvider($em, $this->createRedirectRepository());

        $result = $provider->import([[
            'fromPath' => '/old-page',
            'toUrl' => '/new-page',
            'permanent' => true,
        ]]);

        $this->assertSame(['created' => 1, 'updated' => 0], $result);
        $this->assertSame('/old-page', $persisted[0]->getFromPath());
        $this->assertSame('/new-page', $persisted[0]->getToUrl());
        $this->assertTrue($persisted[0]->isPermanent());
    }

    public function testImportOverwritesAnExistingRedirect(): void
    {
        $existing = (new Redirect())->setFromPath('/old-page')->setToUrl('/somewhere')->setPermanent(false);

        $provider = new RedirectImportProvider($this->createStub(EntityManagerInterface::class), $this->createRedirectRepository($existing));

        $result = $provider->import([[
            'fromPath' => '/old-page',
            'toUrl' => '/new-page',
            'permanent' => true,
        ]]);

        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertSame('/new-page', $existing->getToUrl());
        $this->assertTrue($existing->isPermanent());
    }

    // A url declared gone travels between environments like any other row
    public function testImportKeepsTheGoneFlag(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new RedirectImportProvider($em, $this->createRedirectRepository());

        $provider->import([[
            'fromPath' => '/removed',
            'toUrl' => null,
            'permanent' => true,
            'gone' => true,
        ]]);

        $this->assertTrue($persisted[0]->isGone());
        $this->assertNull($persisted[0]->getToUrl());
    }

    // An export predating the field keeps importing as the plain redirect it was
    public function testImportDefaultsToNotGoneWhenTheFieldIsAbsent(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new RedirectImportProvider($em, $this->createRedirectRepository());

        $provider->import([[
            'fromPath' => '/old-page',
            'toUrl' => '/new-page',
        ]]);

        $this->assertFalse($persisted[0]->isGone());
        $this->assertSame('/new-page', $persisted[0]->getToUrl());
    }

    // Nothing validates an imported item, and a non-gone row without a destination is one RedirectSubscriber can only skip - it never reaches the database
    public function testImportSkipsAnItemWithNeitherDestinationNorGoneFlag(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new RedirectImportProvider($em, $this->createRedirectRepository());

        $result = $provider->import([
            ['fromPath' => '/no-destination'],
            ['fromPath' => '/blank-destination', 'toUrl' => '  '],
            ['fromPath' => '/old-page', 'toUrl' => '/new-page'],
        ]);

        $this->assertSame(['created' => 1, 'updated' => 0], $result);
        $this->assertCount(1, $persisted);
        $this->assertSame('/old-page', $persisted[0]->getFromPath());
    }
}
