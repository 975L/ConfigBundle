<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\ManagementShortcutController;
use PHPUnit\Framework\TestCase;

class ManagementShortcutControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    public function testIndexRedirectsToManagement(): void
    {
        $controller = new ManagementShortcutController();
        $controller->setContainer($this->createContainer([
            'router' => $this->createRouter('/management'),
        ]));

        $response = $controller->index();

        $this->assertSame('/management', $response->getTargetUrl());
    }
}
