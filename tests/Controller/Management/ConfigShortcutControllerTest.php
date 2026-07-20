<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\ConfigShortcutController;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ConfigSqlExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigShortcutControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    private function createController(
        ConfigServiceInterface $configService,
        ?ConfigSqlExporter $configSqlExporter = null,
    ): ConfigShortcutController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ConfigShortcutController(
            $configService,
            $configSqlExporter ?? $this->createStub(ConfigSqlExporter::class),
            $translator,
        );
    }

    public function testClearCacheInvalidatesCacheAndAddsFlashWhenTokenIsValid(): void
    {
        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('invalidateCache');

        $controller = $this->createController($configService);
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->clearCache(new Request([], ['_token' => 'valid-token']));

        $this->assertSame(['flash.config_cache_cleared'], $session->getFlashBag()->get('success'));
        $this->assertSame('/management', $response->getTargetUrl());
    }

    public function testClearCacheDoesNothingWhenCsrfTokenIsInvalid(): void
    {
        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->never())->method('invalidateCache');

        $controller = $this->createController($configService);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
            'router' => $this->createRouter(),
            'request_stack' => $this->createRequestStackWithSession()[0],
        ]));

        $controller->clearCache(new Request([], ['_token' => 'invalid-token']));
    }

    public function testClearCacheDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->clearCache(new Request());
    }

    public function testExportSqlReturnsConfigSqlExporterResponseWhenTokenIsValid(): void
    {
        $exportResponse = new Response('sql content');
        $configSqlExporter = $this->createMock(ConfigSqlExporter::class);
        $configSqlExporter->expects($this->once())->method('export')->willReturn($exportResponse);

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), $configSqlExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $response = $controller->exportSql(new Request([], ['_token' => 'valid-token']));

        $this->assertSame($exportResponse, $response);
    }

    public function testExportSqlRedirectsToManagementWhenCsrfTokenIsInvalid(): void
    {
        $configSqlExporter = $this->createMock(ConfigSqlExporter::class);
        $configSqlExporter->expects($this->never())->method('export');

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), $configSqlExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
            'router' => $this->createRouter(),
        ]));

        $response = $controller->exportSql(new Request([], ['_token' => 'invalid-token']));

        $this->assertSame('/management', $response->getTargetUrl());
    }

    public function testExportSqlDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->exportSql(new Request());
    }
}
