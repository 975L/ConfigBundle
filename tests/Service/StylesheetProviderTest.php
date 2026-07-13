<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\StylesheetProvider;
use c975L\UiBundle\Contract\BundleStylesheetManagementProviderInterface;
use PHPUnit\Framework\TestCase;

class StylesheetProviderTest extends TestCase
{
    public function testGetManagementStylesheetsReturnsTheBundlesOwnMinifiedCssPath(): void
    {
        $provider = new StylesheetProvider();

        $this->assertInstanceOf(BundleStylesheetManagementProviderInterface::class, $provider);
        $this->assertSame(['bundles/c975lconfig/css/management.min.css'], $provider->getManagementStylesheets());
    }
}
