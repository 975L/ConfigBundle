<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\MaintenanceShortcutController;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Tests\Repository\ConfigRepositoryFindOneBySlugFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class MaintenanceShortcutControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    private function createConfigService(bool $currentlyEnabled): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturn('site-role-admin');
        $service->method('getBool')->willReturn($currentlyEnabled);

        return $service;
    }

    private function createController(
        ?Config $config,
        EntityManagerInterface $manager,
        ConfigServiceInterface $configService,
    ): MaintenanceShortcutController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new MaintenanceShortcutController(
            new ConfigRepositoryFindOneBySlugFixture($config),
            $manager,
            $configService,
            $translator,
        );
    }

    public function testToggleMaintenanceFlipsTheConfigValueAndFlushesWhenTokenIsValid(): void
    {
        $config = (new Config())->setSlug('site-maintenance')->setValue(false);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('flush');

        $configService = $this->createConfigService(currentlyEnabled: false);
        $controller = $this->createController($config, $manager, $configService);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $this->createRequestStackWithSession()[0],
        ]));

        $request = new Request([], ['_token' => 'valid-token']);
        $controller->toggleMaintenance($request);

        $this->assertSame('true', $config->getValue());
    }

    public function testToggleMaintenanceInvalidatesCacheAndAddsFlashOnSuccess(): void
    {
        $config = (new Config())->setSlug('site-maintenance')->setValue(false);
        $manager = $this->createStub(EntityManagerInterface::class);

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('site-role-admin');
        $configService->method('getBool')->willReturn(false);
        $configService->expects($this->once())->method('invalidateCache');

        $controller = $this->createController($config, $manager, $configService);
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $controller->toggleMaintenance(new Request([], ['_token' => 'valid-token']));

        $this->assertSame(['flash.maintenance_enabled'], $session->getFlashBag()->get('success'));
    }

    public function testToggleMaintenanceDoesNothingWhenCsrfTokenIsInvalid(): void
    {
        $config = (new Config())->setSlug('site-maintenance')->setValue(false);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('flush');

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('site-role-admin');
        $configService->expects($this->never())->method('invalidateCache');

        $controller = $this->createController($config, $manager, $configService);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
            'router' => $this->createRouter(),
            'request_stack' => $this->createRequestStackWithSession()[0],
        ]));

        $controller->toggleMaintenance(new Request([], ['_token' => 'invalid-token']));

        $this->assertSame('false', $config->getValue());
    }

    public function testToggleMaintenanceDoesNothingWhenConfigIsMissing(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('flush');

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('site-role-admin');
        $configService->expects($this->never())->method('invalidateCache');

        $controller = $this->createController(null, $manager, $configService);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $this->createRequestStackWithSession()[0],
        ]));

        $response = $controller->toggleMaintenance(new Request([], ['_token' => 'valid-token']));

        $this->assertSame('/management', $response->getTargetUrl());
    }

    public function testToggleMaintenanceDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);

        $config = (new Config())->setSlug('site-maintenance')->setValue(false);
        $controller = $this->createController(
            $config,
            $this->createStub(EntityManagerInterface::class),
            $this->createConfigService(currentlyEnabled: false),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->toggleMaintenance(new Request([], ['_token' => 'valid-token']));
    }
}
