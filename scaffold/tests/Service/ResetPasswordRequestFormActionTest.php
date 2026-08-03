<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\ResetPasswordRequestFormAction;
use c975L\UiBundle\Entity\Form;
use c975L\UiBundle\Service\EmailService;
use c975L\UiBundle\Service\EmailTemplateRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

class ResetPasswordRequestFormActionTest extends TestCase
{
    private function createEntityManager(?User $user): EntityManagerInterface
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnMap([
            [User::class, $repository],
        ]);

        return $entityManager;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    // Returns a rendered document by default, so the "send" path is the one exercised unless a test says otherwise
    private function createEmailTemplateRenderer(?string $html = '<html>rendered</html>'): EmailTemplateRenderer
    {
        $renderer = $this->createStub(EmailTemplateRenderer::class);
        $renderer->method('renderNamed')->willReturn($html);

        return $renderer;
    }

    private function createUrlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/reset-password/reset/token');

        return $urlGenerator;
    }

    public function testGetKeyReturnsResetPasswordRequest(): void
    {
        $action = new ResetPasswordRequestFormAction(
            $this->createEntityManager(null),
            $this->createStub(ResetPasswordHelperInterface::class),
            $this->createStub(EmailService::class),
            $this->createEmailTemplateRenderer(),
            $this->createTranslator(),
            $this->createUrlGenerator(),
        );

        $this->assertSame('reset_password_request', $action->getKey());
    }

    // Never reveals whether an account exists - same generic "form_submitted" flash either way
    public function testHandleReturnsTrueWithoutSendingWhenUserIsNotFound(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new ResetPasswordRequestFormAction(
            $this->createEntityManager(null),
            $this->createStub(ResetPasswordHelperInterface::class),
            $emailService,
            $this->createEmailTemplateRenderer(),
            $this->createTranslator(),
            $this->createUrlGenerator(),
        );

        $this->assertTrue($action->handle(new Form(), ['email' => 'unknown@example.test']));
    }

    public function testHandleReturnsTrueWithoutSendingWhenTokenGenerationFails(): void
    {
        $resetPasswordHelper = $this->createStub(ResetPasswordHelperInterface::class);
        $resetPasswordHelper->method('generateResetToken')->willThrowException(new TooManyPasswordRequestsException(new \DateTime()));

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new ResetPasswordRequestFormAction(
            $this->createEntityManager(new User()),
            $resetPasswordHelper,
            $emailService,
            $this->createEmailTemplateRenderer(),
            $this->createTranslator(),
            $this->createUrlGenerator(),
        );

        $this->assertTrue($action->handle(new Form(), ['email' => 'someone@example.test']));
    }

    public function testHandleSendsResetEmailAndReturnsTrueWhenUserIsFound(): void
    {
        $resetPasswordHelper = $this->createStub(ResetPasswordHelperInterface::class);
        $resetPasswordHelper->method('generateResetToken')->willReturn(new ResetPasswordToken('token', new \DateTime('+1 hour'), time()));

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())->method('send');

        $action = new ResetPasswordRequestFormAction(
            $this->createEntityManager((new User())->setEmail('someone@example.test')),
            $resetPasswordHelper,
            $emailService,
            $this->createEmailTemplateRenderer(),
            $this->createTranslator(),
            $this->createUrlGenerator(),
        );

        $this->assertTrue($action->handle(new Form(), ['email' => 'someone@example.test']));
    }

    // An admin who renamed or deleted the "password_reset" EmailTemplate gets no email at all rather than an empty one - still reported as a success, the "never reveal" stance being unchanged
    public function testHandleReturnsTrueWithoutSendingWhenTheEmailTemplateIsGone(): void
    {
        $resetPasswordHelper = $this->createStub(ResetPasswordHelperInterface::class);
        $resetPasswordHelper->method('generateResetToken')->willReturn(new ResetPasswordToken('token', new \DateTime('+1 hour'), time()));

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new ResetPasswordRequestFormAction(
            $this->createEntityManager((new User())->setEmail('someone@example.test')),
            $resetPasswordHelper,
            $emailService,
            $this->createEmailTemplateRenderer(null),
            $this->createTranslator(),
            $this->createUrlGenerator(),
        );

        $this->assertTrue($action->handle(new Form(), ['email' => 'someone@example.test']));
    }
}
