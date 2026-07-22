<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\ContentImportController;
use c975L\ConfigBundle\Management\ImportDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class ContentImportControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    private function createController(?ImportDispatcher $importDispatcher = null): ContentImportController
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ContentImportController(
            $importDispatcher ?? $this->createStub(ImportDispatcher::class),
            $translator,
        );
    }

    // Builds a real zip fixture: manifest.json ($manifestContent as-is, eg. json_encode(...) or a deliberately broken string) plus any extra $entries (archive path => content)
    private function createZipUpload(?string $manifestContent, array $entries = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'content_import_test_') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if (null !== $manifestContent) {
            $zip->addFromString('manifest.json', $manifestContent);
        }
        foreach ($entries as $entryPath => $content) {
            $zip->addFromString($entryPath, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'export.zip', 'application/zip', null, true);
    }

    private function createNonZipUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'content_import_test_');
        file_put_contents($path, 'not a zip');

        return new UploadedFile($path, 'export.zip', 'application/zip', null, true);
    }

    public function testIndexDeniesAccessWhenNotSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->index(new Request());
    }

    public function testIndexRendersTheUploadFormOnGet(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('@c975LConfig/management/content_import.html.twig', [])
            ->willReturn('<form></form>');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'twig' => $twig,
        ]));

        $response = $controller->index(new Request());

        $this->assertSame('<form></form>', $response->getContent());
    }

    public function testIndexRedirectsWhenCsrfTokenIsInvalid(): void
    {
        $importDispatcher = $this->createMock(ImportDispatcher::class);
        $importDispatcher->expects($this->never())->method('dispatch');

        $controller = $this->createController($importDispatcher);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
            'router' => $this->createRouter(),
            'request_stack' => $this->createRequestStackWithSession()[0],
        ]));

        $response = $controller->index(Request::create('/content-import', 'POST', ['_token' => 'invalid']));

        $this->assertSame('/management', $response->getTargetUrl());
    }

    public function testIndexFlashesDangerWhenNoFileUploaded(): void
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $controller->index(Request::create('/content-import', 'POST', ['_token' => 'valid']));

        $this->assertSame(['flash.content_import_no_file'], $session->getFlashBag()->get('danger'));
    }

    public function testIndexFlashesDangerWhenFileIsNotAValidZip(): void
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $file = $this->createNonZipUpload();

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $request = Request::create('/content-import', 'POST', ['_token' => 'valid']);
        $request->files->set('export', $file);
        $controller->index($request);

        $this->assertSame(['flash.content_import_invalid_zip'], $session->getFlashBag()->get('danger'));
        unlink($file->getPathname());
    }

    public function testIndexFlashesDangerWhenAZipEntryTriesToEscapeTheExtractionDirectory(): void
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $file = $this->createZipUpload(json_encode(['kind' => 'site_page', 'items' => []]), ['../evil.txt' => 'gotcha']);

        $importDispatcher = $this->createMock(ImportDispatcher::class);
        $importDispatcher->expects($this->never())->method('dispatch');

        $controller = $this->createController($importDispatcher);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $request = Request::create('/content-import', 'POST', ['_token' => 'valid']);
        $request->files->set('export', $file);
        $controller->index($request);

        $this->assertSame(['flash.content_import_invalid_zip'], $session->getFlashBag()->get('danger'));
        unlink($file->getPathname());
    }

    public function testIndexFlashesDangerWhenManifestIsInvalid(): void
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $file = $this->createZipUpload('not json');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $request = Request::create('/content-import', 'POST', ['_token' => 'valid']);
        $request->files->set('export', $file);
        $controller->index($request);

        $this->assertSame(['flash.content_import_invalid_json'], $session->getFlashBag()->get('danger'));
        unlink($file->getPathname());
    }

    public function testIndexFlashesDangerWhenKindIsNotSupported(): void
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $file = $this->createZipUpload(json_encode(['kind' => 'unknown_kind', 'items' => []]));

        $importDispatcher = $this->createMock(ImportDispatcher::class);
        $importDispatcher->expects($this->once())
            ->method('dispatch')
            ->with('unknown_kind', [], $this->anything())
            ->willReturn(null);

        $controller = $this->createController($importDispatcher);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $request = Request::create('/content-import', 'POST', ['_token' => 'valid']);
        $request->files->set('export', $file);
        $controller->index($request);

        $this->assertSame(['flash.content_import_unsupported_kind'], $session->getFlashBag()->get('danger'));
        unlink($file->getPathname());
    }

    public function testIndexFlashesSuccessDispatchesWithExtractedFilesAndCleansUpAfterwards(): void
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $items = [['slug' => 'home', 'blocks' => [['medias' => [['file' => 'files/photo.jpg']]]]]];
        $file = $this->createZipUpload(
            json_encode(['kind' => 'site_page', 'items' => $items]),
            ['files/photo.jpg' => 'binary-content'],
        );

        $capturedFilesDir = null;
        $importDispatcher = $this->createMock(ImportDispatcher::class);
        $importDispatcher->expects($this->once())
            ->method('dispatch')
            ->with('site_page', $items, $this->callback(function (string $filesDir) use (&$capturedFilesDir): bool {
                $capturedFilesDir = $filesDir;
                // The extracted file must be readable from inside the callback, before cleanup runs
                return 'binary-content' === file_get_contents($filesDir . '/files/photo.jpg');
            }))
            ->willReturn(['created' => 2, 'updated' => 1]);

        $controller = $this->createController($importDispatcher);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $request = Request::create('/content-import', 'POST', ['_token' => 'valid']);
        $request->files->set('export', $file);
        $controller->index($request);

        $this->assertSame(['flash.content_import_success'], $session->getFlashBag()->get('success'));
        $this->assertNotNull($capturedFilesDir);
        $this->assertDirectoryDoesNotExist($capturedFilesDir);
        unlink($file->getPathname());
    }
}
