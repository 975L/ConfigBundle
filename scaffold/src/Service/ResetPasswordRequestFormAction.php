<?php

namespace App\Service;

use App\Entity\User;
use c975L\UiBundle\Contract\FormActionInterface;
use c975L\UiBundle\Contract\RequiresAnonymousInterface;
use c975L\UiBundle\Entity\Form;
use c975L\UiBundle\Model\EmailSendRequest;
use c975L\UiBundle\Service\EmailService;
use c975L\UiBundle\Service\EmailTemplateRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

// FormActionInterface provider (key "reset_password_request") for the generic "form" Block/c975L\UiBundle\Controller\FormController - see RegisterFormAction for the equivalent register-side rationale; the Form itself is seeded by ConfigBundle's UserFormSeeder. Also implements RequiresAnonymousInterface: an already-authenticated visitor gets an "already logged in" notice instead of the form.
class ResetPasswordRequestFormAction implements FormActionInterface, RequiresAnonymousInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EmailService $emailService,
        private readonly EmailTemplateRenderer $emailTemplateRenderer,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'reset_password_request';
    }

    // Always returns true, whether or not the submitted email matches an account - never reveals which, same generic "form_submitted" flash either way (see c975L\UiBundle\Controller\FormController::submit())
    public function handle(Form $form, array $submittedData): bool
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $submittedData['email']]);
        if (null === $user) {
            return true;
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            return true;
        }

        // Composed from the admin-editable "password_reset" EmailTemplate and wrapped by whichever bundle registers an EmailLayoutProviderInterface (SiteBundle's branded layout when installed, UiBundle's plain fallback otherwise) - no per-app Twig email file to keep in sync
        $html = $this->emailTemplateRenderer->renderNamed('password_reset', [
            'reset_url' => $this->urlGenerator->generate('app_reset_password', ['token' => $resetToken->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            'expires_at' => $this->translator->trans('text.link_expires_in', [], 'config')
                . ' ' . $this->translator->trans(
                    $resetToken->getExpirationMessageKey(),
                    $resetToken->getExpirationMessageData(),
                    'ResetPasswordBundle'
                ),
        ]);

        if (null === $html) {
            return true;
        }

        $this->emailService->send(new EmailSendRequest(
            subject: $this->translator->trans('label.password_reset_request', [], 'config'),
            context: [],
            html: $html,
            to: (string) $user->getEmail(),
        ));

        return true;
    }
}
