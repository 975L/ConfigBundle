<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\UserRegistrar;
use c975L\UiBundle\Contract\FormActionInterface;
use c975L\UiBundle\Contract\RequiresAnonymousInterface;
use c975L\UiBundle\Entity\Form;
use Symfony\Contracts\Translation\TranslatorInterface;

// FormActionInterface provider (key "register") for the generic "form" Block/c975L\UiBundle\Controller\FormController - register is now a plain c975L\UiBundle\Entity\Form row (site_form/site_form_field) processed the same way as any admin-built form like "contact", seeded by ConfigBundle's UserFormSeeder. Auto-registered by UiBundle's FormActionProviderPass (scans every service implementing FormActionInterface), nothing to wire in services.yaml. Also implements RequiresAnonymousInterface: an already-authenticated visitor gets an "already logged in" notice instead of the form.
class RegisterFormAction implements FormActionInterface, RequiresAnonymousInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserRegistrar $userRegistrar,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getKey(): string
    {
        return 'register';
    }

    // $submittedData keyed by FormField::getName() - "email"/"plainPassword"/"cgu". CGU's own required-checkbox constraint is already enforced generically by FormSubmissionType, nothing to check here.
    public function handle(Form $form, array $submittedData): bool
    {
        // Silently succeed without creating anything or sending any email - same "never reveal" stance as password-reset, avoids leaking which emails are already registered
        if (null !== $this->userRepository->findOneBy(['email' => $submittedData['email']])) {
            return true;
        }

        $user = (new User())->setEmail($submittedData['email']);

        return $this->userRegistrar->register(
            $user,
            $submittedData['plainPassword'],
            'app_verify_email',
            $this->configService->get('site-name') . ' - ' . $this->translator->trans('label.confirm_your_email', [], 'config'),
            (string) $user->getEmail(),
        );
    }
}
