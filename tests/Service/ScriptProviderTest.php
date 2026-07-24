<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\ScriptProvider;
use c975L\UiBundle\Contract\BundleScriptAdminProviderInterface;
use PHPUnit\Framework\TestCase;

class ScriptProviderTest extends TestCase
{
    public function testGetAdminScriptsReturnsTheBundlesOwnControllersEntry(): void
    {
        $provider = new ScriptProvider();

        $this->assertInstanceOf(BundleScriptAdminProviderInterface::class, $provider);
        $this->assertSame(['@c975l/config-bundle/controllers-admin.js'], $provider->getAdminScripts());
    }
}
