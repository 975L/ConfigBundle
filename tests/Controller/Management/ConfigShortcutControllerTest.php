<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Command\ExportTablesCommand;
use c975L\ConfigBundle\Controller\Management\ConfigShortcutController;
use c975L\ConfigBundle\Management\SitemapWriter;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ConfigSqlExporter;
use c975L\ConfigBundle\Service\Export\SyncAllExporter;
use c975L\UiBundle\Entity\Form;
use c975L\UiBundle\Repository\FormRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        ?SyncAllExporter $syncAllExporter = null,
        ?SitemapWriter $sitemapWriter = null,
        ?ExportTablesCommand $exportTablesCommand = null,
        ?FormRepository $formRepository = null,
        ?EntityManagerInterface $entityManager = null,
    ): ConfigShortcutController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ConfigShortcutController(
            $configService,
            $configSqlExporter ?? $this->createStub(ConfigSqlExporter::class),
            $syncAllExporter ?? $this->createStub(SyncAllExporter::class),
            $sitemapWriter ?? $this->createStub(SitemapWriter::class),
            $exportTablesCommand ?? $this->createStub(ExportTablesCommand::class),
            $formRepository ?? $this->createStub(FormRepository::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
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

    public function testExportSyncAllReturnsSyncAllExporterResponseWhenTokenIsValid(): void
    {
        $exportResponse = new BinaryFileResponse(tempnam(sys_get_temp_dir(), 'export_test_'));
        $syncAllExporter = $this->createMock(SyncAllExporter::class);
        $syncAllExporter->expects($this->once())->method('export')->willReturn($exportResponse);

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), null, $syncAllExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $response = $controller->exportSyncAll(new Request([], ['_token' => 'valid-token']));

        $this->assertSame($exportResponse, $response);
    }

    public function testExportSyncAllRedirectsToManagementWhenCsrfTokenIsInvalid(): void
    {
        $syncAllExporter = $this->createMock(SyncAllExporter::class);
        $syncAllExporter->expects($this->never())->method('export');

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), null, $syncAllExporter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
            'router' => $this->createRouter(),
        ]));

        $response = $controller->exportSyncAll(new Request([], ['_token' => 'invalid-token']));

        $this->assertSame('/management', $response->getTargetUrl());
    }

    public function testExportSyncAllDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->exportSyncAll(new Request());
    }

    public function testCreateSitemapsWritesThemAndAddsFlashWhenTokenIsValid(): void
    {
        $sitemapWriter = $this->createMock(SitemapWriter::class);
        $sitemapWriter->expects($this->once())->method('write')->willReturn(['site']);

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), null, null, $sitemapWriter);
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->createSitemaps(new Request([], ['_token' => 'valid-token']));

        $this->assertSame(['flash.config_sitemaps_created'], $session->getFlashBag()->get('success'));
        $this->assertSame('/management', $response->getTargetUrl());
    }

    public function testCreateSitemapsDoesNothingWhenCsrfTokenIsInvalid(): void
    {
        $sitemapWriter = $this->createMock(SitemapWriter::class);
        $sitemapWriter->expects($this->never())->method('write');

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), null, null, $sitemapWriter);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
            'router' => $this->createRouter(),
            'request_stack' => $this->createRequestStackWithSession()[0],
        ]));

        $controller->createSitemaps(new Request([], ['_token' => 'invalid-token']));
    }

    // An unwritable public/ folder must be shown as an error flash, and never as the success one nor as a 500
    public function testCreateSitemapsAddsErrorFlashWhenWritingFails(): void
    {
        $sitemapWriter = $this->createStub(SitemapWriter::class);
        $sitemapWriter->method('write')->willThrowException(new IOException('Failed to write file'));

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), null, null, $sitemapWriter);
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->createSitemaps(new Request([], ['_token' => 'valid-token']));

        $this->assertSame(['flash.config_sitemaps_error'], $session->getFlashBag()->get('error'));
        $this->assertSame([], $session->getFlashBag()->get('success'));
        $this->assertSame('/management', $response->getTargetUrl());
    }

    // Writing files into public/ stays ROLE_SUPER_ADMIN, unlike the read-only export shortcuts
    public function testCreateSitemapsDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->createSitemaps(new Request());
    }

    // Streamed back directly rather than written to var/export - moved here from SiteBundle alongside the command it runs
    public function testExportTablesStreamsTheDumpBackWhenTokenIsValid(): void
    {
        $exportTablesCommand = $this->createMock(ExportTablesCommand::class);
        $exportTablesCommand->expects($this->once())->method('exportTables')->with('site_', null, false)->willReturn([
            'error' => null,
            'message' => '',
            'tables' => ['site_page'],
            'content' => 'INSERT INTO site_page ...',
        ]);

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), exportTablesCommand: $exportTablesCommand);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $response = $controller->exportTables(new Request([], ['_token' => 'valid-token']));

        $this->assertSame('INSERT INTO site_page ...', $response->getContent());
        $this->assertStringContainsString('attachment; filename="site_', (string) $response->headers->get('Content-Disposition'));
    }

    // No table matched the prefix: a warning rather than an empty download the admin would take for a real dump
    public function testExportTablesWarnsAndRedirectsWhenNoTableMatched(): void
    {
        $exportTablesCommand = $this->createStub(ExportTablesCommand::class);
        $exportTablesCommand->method('exportTables')->willReturn(['error' => null, 'message' => 'no table', 'tables' => [], 'content' => '']);

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), exportTablesCommand: $exportTablesCommand);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession()[0],
            'router' => $this->createRouter(),
        ]));

        $this->assertSame(302, $controller->exportTables(new Request([], ['_token' => 'valid-token']))->getStatusCode());
    }

    // Flips the "register" Form's own $enabled flag, the same lever FormController checks before building the form
    public function testRegistrationEnabledToggleFlipsTheRegisterFormAndFlushes(): void
    {
        $form = (new Form())->setName('register')->setEnabled(false);
        $formRepository = $this->createStub(FormRepository::class);
        $formRepository->method('findOneBy')->willReturn($form);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), formRepository: $formRepository, entityManager: $entityManager);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession()[0],
            'router' => $this->createRouter(),
        ]));

        $controller->registrationEnabledToggle(new Request([], ['_token' => 'valid-token']));

        $this->assertTrue($form->isEnabled());
    }

    // Nothing seeded yet: no Form to flip, and nothing flushed either
    public function testRegistrationEnabledToggleDoesNothingWithoutARegisterForm(): void
    {
        $formRepository = $this->createStub(FormRepository::class);
        $formRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $controller = $this->createController($this->createStub(ConfigServiceInterface::class), formRepository: $formRepository, entityManager: $entityManager);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
        ]));

        $this->assertSame(302, $controller->registrationEnabledToggle(new Request([], ['_token' => 'valid-token']))->getStatusCode());
    }
}
