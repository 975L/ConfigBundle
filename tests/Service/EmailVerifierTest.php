<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\EmailVerifier;
use c975L\ConfigBundle\Tests\Fixtures\UserStub;
use c975L\UiBundle\Model\EmailSendRequest;
use c975L\UiBundle\Service\EmailService;
use c975L\UiBundle\Service\EmailTemplateRenderer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

// Moved from the app-copied scaffold (previously App\Tests\Security\EmailVerifierTest) alongside the class it tests - see UPGRADE.md
class EmailVerifierTest extends TestCase
{
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    // The body comes from the admin-editable "account_validation" EmailTemplate, already wrapped in whichever email layout is registered - so it goes out as pre-rendered html, never as a Twig path this bundle would have to own
    public function testSendEmailConfirmationSignsRendersTheTemplateAndSendsThroughEmailService(): void
    {
        $user = (new UserStub('user@example.test'))->withId(42);

        $signature = new VerifyEmailSignatureComponents(
            new \DateTimeImmutable('+1 hour'),
            'https://example.test/verification/email?signed=1',
            time()
        );

        $verifyEmailHelper = $this->createMock(VerifyEmailHelperInterface::class);
        $verifyEmailHelper->expects($this->once())
            ->method('generateSignature')
            ->with('app_verify_email', '42', 'user@example.test', ['id' => 42])
            ->willReturn($signature);

        $emailTemplateRenderer = $this->createMock(EmailTemplateRenderer::class);
        $emailTemplateRenderer->expects($this->once())
            ->method('renderNamed')
            ->with(EmailVerifier::EMAIL_TEMPLATE, $this->callback(static function (array $variables) use ($signature): bool {
                return $variables['signed_url'] === $signature->getSignedUrl()
                    && str_starts_with($variables['expires_at'], 'text.link_expires_in ');
            }))
            ->willReturn('<html>rendered</html>');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with($this->callback(static function (EmailSendRequest $request): bool {
                return 'Confirm your email' === $request->subject
                    && '<html>rendered</html>' === $request->html
                    && null === $request->template
                    && 'user@example.test' === $request->to;
            }))
            ->willReturn(true);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $emailVerifier = new EmailVerifier($verifyEmailHelper, $emailService, $emailTemplateRenderer, $this->createTranslator(), $entityManager);
        $result = $emailVerifier->sendEmailConfirmation('app_verify_email', $user, 'Confirm your email', 'user@example.test');

        $this->assertTrue($result);
    }

    // An admin who renamed or deleted the template gets no email at all rather than an empty one, and the caller is told
    public function testSendEmailConfirmationReturnsFalseWhenTheTemplateIsGone(): void
    {
        $user = (new UserStub('user@example.test'))->withId(42);

        $verifyEmailHelper = $this->createStub(VerifyEmailHelperInterface::class);
        $verifyEmailHelper->method('generateSignature')->willReturn(new VerifyEmailSignatureComponents(
            new \DateTimeImmutable('+1 hour'),
            'https://example.test/verification/email?signed=1',
            time()
        ));

        $emailTemplateRenderer = $this->createStub(EmailTemplateRenderer::class);
        $emailTemplateRenderer->method('renderNamed')->willReturn(null);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $emailVerifier = new EmailVerifier($verifyEmailHelper, $emailService, $emailTemplateRenderer, $this->createTranslator(), $this->createStub(EntityManagerInterface::class));

        $this->assertFalse($emailVerifier->sendEmailConfirmation('app_verify_email', $user, 'Confirm your email', 'user@example.test'));
    }

    public function testHandleEmailConfirmationMarksUserAsVerifiedAndEnabled(): void
    {
        $user = new UserStub('user@example.test');
        $request = Request::create('/verification/email?id=42');

        // validateEmailConfirmationFromRequest() is only documented via @method on the interface (it lives on the final VerifyEmailHelper class), so PHPUnit's mock generator can't stub it from the interface alone. A hand-written stub can.
        $verifyEmailHelper = new class ($request, $user) implements VerifyEmailHelperInterface {
            public int $calls = 0;

            public function __construct(
                private readonly Request $expectedRequest,
                private readonly UserStub $expectedUser,
            ) {
            }

            /** @param array<string, mixed> $extraParams */
            public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
            {
                throw new \LogicException('Not expected to be called.');
            }

            public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
            {
                throw new \LogicException('Not expected to be called.');
            }

            public function validateEmailConfirmationFromRequest(Request $request, string $userId, string $userEmail): void
            {
                Assert::assertSame($this->expectedRequest, $request);
                Assert::assertSame('', $userId);
                Assert::assertSame($this->expectedUser->getUserIdentifier(), $userEmail);
                ++$this->calls;
            }
        };

        $emailService = $this->createStub(EmailService::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($user);
        $entityManager->expects($this->once())->method('flush');

        $emailVerifier = new EmailVerifier($verifyEmailHelper, $emailService, $this->createStub(EmailTemplateRenderer::class), $this->createTranslator(), $entityManager);
        $emailVerifier->handleEmailConfirmation($request, $user);

        $this->assertSame(1, $verifyEmailHelper->calls);
        $this->assertTrue($user->isVerified());
        $this->assertTrue($user->isEnabled());
    }
}
