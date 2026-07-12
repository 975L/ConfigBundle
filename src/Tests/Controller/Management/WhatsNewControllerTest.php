<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\WhatsNewController;
use c975L\ConfigBundle\Management\WhatsNewBuilder;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class WhatsNewControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    public function testIndexRendersTemplateWithBuilderEntries(): void
    {
        $whatsNewBuilder = $this->createStub(WhatsNewBuilder::class);
        $whatsNewBuilder->method('getAll')->willReturn(['entry-1', 'entry-2']);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('site-role-admin');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                '@c975LConfig/management/whatsnew/index.html.twig',
                ['entries' => ['entry-1', 'entry-2']],
            )
            ->willReturn('<html></html>');

        $controller = new WhatsNewController($whatsNewBuilder, $configService);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'twig' => $twig,
        ]));

        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<html></html>', $response->getContent());
    }

    public function testIndexDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('site-role-admin');

        $controller = new WhatsNewController($this->createStub(WhatsNewBuilder::class), $configService);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->index();
    }
}
