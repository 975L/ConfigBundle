<?php

namespace App\Controller;

use App\Repository\UserRepository;
use c975L\ConfigBundle\Service\EmailVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

// Registration itself ("register" Form/FormField, honeypot, rate-limiting, password hashing, verification email) is handled by the generic "form" Block/c975L\UiBundle\Controller\FormController + App\Service\RegisterFormAction, same mechanism as "contact" - this controller only keeps what a generic Form can't do: consuming the signed email-verification link.
class RegistrationController extends AbstractController
{
    public function __construct(
        private EmailVerifier $emailVerifier,
    ) {
    }

    // VERIFY EMAIL
    #[Route(
        path: '/verification/email',
        name: 'app_verify_email',
        methods: ['GET']
    )]
    public function verifyUserEmail(Request $request, TranslatorInterface $translator, UserRepository $userRepository): Response
    {
        // Every outcome lands on the login form: the link is opened fresh with no Referer to return to, the visitor isn't authenticated yet, and logging in is where the whole flow was heading anyway. A route this scaffold owns itself, rather than a SiteBundle Page, is the only target that exists whether or not a site foundation sits on top of this bundle
        $id = $request->query->get('id');
        if (null === $id) {
            return $this->redirectToRoute('app_login');
        }

        $user = $userRepository->find($id);

        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }

        // validate email confirmation link, sets User::isVerified=true and persists
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_login');
        }

        $this->addFlash('success', $translator->trans('label.email_address_verified', [], 'config'));

        return $this->redirectToRoute('app_login');
    }
}
