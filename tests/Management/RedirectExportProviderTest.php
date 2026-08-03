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
use c975L\ConfigBundle\Management\RedirectExportProvider;
use c975L\ConfigBundle\Management\RedirectImportProvider;
use c975L\ConfigBundle\Repository\RedirectRepository;
use PHPUnit\Framework\TestCase;

class RedirectExportProviderTest extends TestCase
{
    public function testGetKindMatchesRedirectImportProvider(): void
    {
        $provider = new RedirectExportProvider($this->createStub(RedirectRepository::class));

        $this->assertSame(RedirectImportProvider::KIND, $provider->getKind());
    }

    public function testExportAllSerializesEveryRedirectFromTheRepository(): void
    {
        $redirect = (new Redirect())->setFromPath('/old-page')->setToUrl('/new-page')->setPermanent(true);

        $redirectRepository = $this->createMock(RedirectRepository::class);
        $redirectRepository->expects($this->once())->method('findAll')->willReturn([$redirect]);

        $data = (new RedirectExportProvider($redirectRepository))->exportAll();

        $this->assertSame([[
            'fromPath' => '/old-page',
            'toUrl' => '/new-page',
            'permanent' => true,
            'gone' => false,
        ]], $data['items']);
        $this->assertSame([], $data['files']);
    }

    // A "gone" row carries no target: the export must keep the null rather than drop the key, which the import reads back as-is
    public function testExportAllSerializesAGoneRedirectWithoutATarget(): void
    {
        $redirect = (new Redirect())->setFromPath('/apidoc/*')->setGone(true);

        $redirectRepository = $this->createMock(RedirectRepository::class);
        $redirectRepository->expects($this->once())->method('findAll')->willReturn([$redirect]);

        $data = (new RedirectExportProvider($redirectRepository))->exportAll();

        $this->assertSame([[
            'fromPath' => '/apidoc/*',
            'toUrl' => null,
            'permanent' => true,
            'gone' => true,
        ]], $data['items']);
    }
}
