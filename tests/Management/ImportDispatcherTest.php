<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ImportDispatcher;
use c975L\ConfigBundle\Management\ImportProviderInterface;
use PHPUnit\Framework\TestCase;

class ImportDispatcherTest extends TestCase
{
    public function testDispatchRoutesToTheFirstProviderSupportingTheKind(): void
    {
        $unrelatedProvider = $this->createMock(ImportProviderInterface::class);
        $unrelatedProvider->method('supportsImport')->willReturn(false);
        $unrelatedProvider->expects($this->never())->method('import');

        $matchingProvider = $this->createMock(ImportProviderInterface::class);
        $matchingProvider->method('supportsImport')->willReturnCallback(
            static fn (string $kind): bool => 'site_page' === $kind,
        );
        $matchingProvider->expects($this->once())
            ->method('import')
            ->with([['slug' => 'home']], '/tmp/extracted')
            ->willReturn(['created' => 1, 'updated' => 0]);

        $dispatcher = new ImportDispatcher([$unrelatedProvider, $matchingProvider]);

        $result = $dispatcher->dispatch('site_page', [['slug' => 'home']], '/tmp/extracted');

        $this->assertSame(['created' => 1, 'updated' => 0], $result);
    }

    public function testDispatchReturnsNullWhenNoProviderSupportsTheKind(): void
    {
        $provider = $this->createMock(ImportProviderInterface::class);
        $provider->method('supportsImport')->willReturn(false);
        $provider->expects($this->never())->method('import');

        $dispatcher = new ImportDispatcher([$provider]);

        $this->assertNull($dispatcher->dispatch('unknown_kind', []));
    }

    public function testDispatchReturnsNullWithNoProvidersAtAll(): void
    {
        $dispatcher = new ImportDispatcher([]);

        $this->assertNull($dispatcher->dispatch('site_page', []));
    }
}
