<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Command\MessengerCleanupCommand;
use c975L\ConfigBundle\Controller\Management\MessengerFailedController;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\MessengerFailedMessageService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;

class MessengerFailedControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    private function createController(
        MessengerFailedMessageService $service,
        ?MessengerCleanupCommand $cleanupCommand = null,
    ): MessengerFailedController {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_ADMIN');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new MessengerFailedController(
            $service,
            $cleanupCommand ?? $this->createStub(MessengerCleanupCommand::class),
            $configService,
            $translator,
        );
    }

    // Returns [Controller, Session] so the test can assert on the flashes an action left behind
    private function prepare(MessengerFailedController $controller, bool $csrfValid = true): Session
    {
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager($csrfValid),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        return $session;
    }

    private function createRequest(array $parameters = []): Request
    {
        return new Request([], $parameters + ['_token' => 'valid-token']);
    }

    public function testPurgeNowRunsTheCleanupAndReportsHowManyWereRemoved(): void
    {
        $cleanupCommand = $this->createMock(MessengerCleanupCommand::class);
        $cleanupCommand->expects($this->once())->method('cleanup')->willReturn([
            'purged' => 7, 'important' => 0, 'newImportant' => 0, 'alerted' => false,
        ]);

        $controller = $this->createController($this->createStub(MessengerFailedMessageService::class), $cleanupCommand);
        $session = $this->prepare($controller);

        $response = $controller->purgeNow($this->createRequest());

        $this->assertSame(['flash.messenger_purged'], $session->getFlashBag()->get('success'));
        $this->assertSame('/management', $response->getTargetUrl());
    }

    public function testPurgeNowDoesNothingWhenTheCsrfTokenIsInvalid(): void
    {
        $cleanupCommand = $this->createMock(MessengerCleanupCommand::class);
        $cleanupCommand->expects($this->never())->method('cleanup');

        $controller = $this->createController($this->createStub(MessengerFailedMessageService::class), $cleanupCommand);
        $session = $this->prepare($controller, false);

        $controller->purgeNow($this->createRequest());

        $this->assertSame([], $session->getFlashBag()->get('success'));
    }

    public function testRetryReportsASuccessfulReplay(): void
    {
        $service = $this->createStub(MessengerFailedMessageService::class);
        $service->method('retry')->willReturn(['found' => true, 'success' => true, 'error' => null]);

        $controller = $this->createController($service);
        $session = $this->prepare($controller);

        $controller->retry(12, $this->createRequest());

        $this->assertSame(['flash.messenger_retry_success'], $session->getFlashBag()->get('success'));
    }

    // The new error is what tells the admin whether retrying again is worth it or the message should just be deleted
    public function testRetryReportsTheNewErrorWhenTheReplayFailsAgain(): void
    {
        $service = $this->createStub(MessengerFailedMessageService::class);
        $service->method('retry')->willReturn(['found' => true, 'success' => false, 'error' => 'Connection refused']);

        $controller = $this->createController($service);
        $session = $this->prepare($controller);

        $controller->retry(12, $this->createRequest());

        $this->assertSame(['flash.messenger_retry_failed'], $session->getFlashBag()->get('danger'));
    }

    // A message purged or replayed from another tab in the meantime, or an app with no failure transport to replay it through
    public function testRetryWarnsWhenTheMessageIsNoLongerThere(): void
    {
        $service = $this->createStub(MessengerFailedMessageService::class);
        $service->method('retry')->willReturn(['found' => false, 'success' => false, 'error' => null]);

        $controller = $this->createController($service);
        $session = $this->prepare($controller);

        $controller->retry(12, $this->createRequest());

        $this->assertSame(['flash.messenger_not_found'], $session->getFlashBag()->get('warning'));
    }

    public function testDeleteRemovesTheMessage(): void
    {
        $service = $this->createMock(MessengerFailedMessageService::class);
        $service->expects($this->once())->method('deleteById')->with(12)->willReturn(true);

        $controller = $this->createController($service);
        $session = $this->prepare($controller);

        $controller->delete(12, $this->createRequest());

        $this->assertSame(['flash.messenger_deleted'], $session->getFlashBag()->get('success'));
    }

    public function testDeleteGroupRemovesEveryPostedIdAsIntegers(): void
    {
        $service = $this->createMock(MessengerFailedMessageService::class);
        $service->expects($this->once())->method('deleteByIds')->with([3, 7, 11])->willReturn(3);

        $controller = $this->createController($service);
        $session = $this->prepare($controller);

        $controller->deleteGroup($this->createRequest(['ids' => ['3', '7', '11']]));

        $this->assertSame(['flash.messenger_group_deleted'], $session->getFlashBag()->get('success'));
    }

    public function testDeleteGroupDoesNothingWhenTheCsrfTokenIsInvalid(): void
    {
        $service = $this->createMock(MessengerFailedMessageService::class);
        $service->expects($this->never())->method('deleteByIds');

        $controller = $this->createController($service);
        $this->prepare($controller, false);

        $controller->deleteGroup($this->createRequest(['ids' => ['3']]));
    }
}
