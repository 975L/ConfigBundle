<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use c975L\UiBundle\Entity\EmailBlock;
use c975L\UiBundle\Entity\FormField;
use c975L\UiBundle\Service\FormSeeder;
use Doctrine\ORM\EntityManagerInterface;

// The "register" and "reset_password_request" Forms and the two emails they send, seeded here rather than by SiteBundle's DefaultPagesImporter as they used to be: an app running Config+Ui plus a satellite bundle but no site foundation still needs an account to be creatable. Idempotent, so calling it again on an existing site is a no-op
class UserFormSeeder
{
    // name => [type, label, url], one set per locale - FormSubmissionType renders FormField labels as literal text (translation_domain: false, an admin is expected to type real text, not a key), so these have to be actual words, picked once for kernel.default_locale since Form::$name is unique site-wide. "cgu"'s url points at that locale's own terms-of-use legal page, kept as a plain relative "/pages/{slug}" path (no router involved) since it's only ever read back once by FormSubmissionType - a site without those pages simply shows the label without a link
    private const REGISTER_CORE_FIELDS = [
        'fr' => [
            'email' => [FormField::TYPE_EMAIL, 'Email', null],
            'plainPassword' => [FormField::TYPE_PASSWORD_REPEATED, 'Mot de passe', null],
            'cgu' => [FormField::TYPE_CHECKBOX, 'J\'accepte les conditions générales d\'utilisation', '/pages/conditions-generales-d-utilisation'],
        ],
        'en' => [
            'email' => [FormField::TYPE_EMAIL, 'Email', null],
            'plainPassword' => [FormField::TYPE_PASSWORD_REPEATED, 'Password', null],
            'cgu' => [FormField::TYPE_CHECKBOX, 'I accept the terms of use', '/pages/terms-of-use'],
        ],
        'es' => [
            'email' => [FormField::TYPE_EMAIL, 'Email', null],
            'plainPassword' => [FormField::TYPE_PASSWORD_REPEATED, 'Contraseña', null],
            'cgu' => [FormField::TYPE_CHECKBOX, 'Acepto las condiciones de uso', '/pages/condiciones-de-uso'],
        ],
    ];

    // Shown under the register form's submit button (see UiBundle's Form::getLinks()) - a visitor who already has an account, or who came here because they lost their password, would otherwise be stuck on a form that can't help them. Same locale keying and same plain-path convention as the "cgu" url above: "/login" is the scaffolded App\Controller\SecurityController route, the other one that locale's own default page (see SiteBundle's DefaultPagesImporter) - a site without that page simply has one dead link to fix or drop, the links staying fully editable
    private const REGISTER_LINKS = [
        'fr' => [
            ['label' => 'J\'ai déjà un compte, me connecter', 'url' => '/login'],
            ['label' => 'Mot de passe oublié ?', 'url' => '/pages/mot-de-passe-oublie'],
        ],
        'en' => [
            ['label' => 'I already have an account, sign in', 'url' => '/login'],
            ['label' => 'Forgot your password?', 'url' => '/pages/forgot-password'],
        ],
        'es' => [
            ['label' => 'Ya tengo una cuenta, iniciar sesión', 'url' => '/login'],
            ['label' => '¿Ha olvidado su contraseña?', 'url' => '/pages/contrasena-olvidada'],
        ],
    ];

    // The way back out of the "I forgot my password" form, for a visitor who remembers it after all or who has no account yet
    private const RESET_PASSWORD_REQUEST_LINKS = [
        'fr' => [
            ['label' => 'Retour à la connexion', 'url' => '/login'],
            ['label' => 'Créer un compte', 'url' => '/pages/creer-un-compte'],
        ],
        'en' => [
            ['label' => 'Back to sign in', 'url' => '/login'],
            ['label' => 'Create an account', 'url' => '/pages/register'],
        ],
        'es' => [
            ['label' => 'Volver al inicio de sesión', 'url' => '/login'],
            ['label' => 'Crear una cuenta', 'url' => '/pages/crear-una-cuenta'],
        ],
    ];

    // Same shape, for the "reset_password_request" Form - see the scaffolded App\Service\ResetPasswordRequestFormAction for the FormActionInterface key processing it
    private const RESET_PASSWORD_REQUEST_CORE_FIELDS = [
        'fr' => [
            'email' => [FormField::TYPE_EMAIL, 'Email', null],
        ],
        'en' => [
            'email' => [FormField::TYPE_EMAIL, 'Email', null],
        ],
        'es' => [
            'email' => [FormField::TYPE_EMAIL, 'Email', null],
        ],
    ];

    // One EmailBlock tuple set per locale, unused positions left null. "{{ signed_url }}"/"{{ expires_at }}" are resolved by EmailVerifier at send time
    private const ACCOUNT_VALIDATION_BLOCKS = [
        'fr' => [
            [EmailBlock::TYPE_HEADING, 'Confirmez votre adresse email', EmailBlock::LEVEL_H1, null, null, null],
            [EmailBlock::TYPE_TEXT, null, null, 'Merci de votre inscription. Cliquez sur le bouton ci-dessous pour confirmer votre adresse email.', null, null],
            [EmailBlock::TYPE_BUTTON, null, null, null, 'Confirmer mon email', '{{ signed_url }}'],
            [EmailBlock::TYPE_TEXT, null, null, '{{ expires_at }}', null, null],
        ],
        'en' => [
            [EmailBlock::TYPE_HEADING, 'Confirm your email address', EmailBlock::LEVEL_H1, null, null, null],
            [EmailBlock::TYPE_TEXT, null, null, 'Thanks for registering. Click the button below to confirm your email address.', null, null],
            [EmailBlock::TYPE_BUTTON, null, null, null, 'Confirm my email', '{{ signed_url }}'],
            [EmailBlock::TYPE_TEXT, null, null, '{{ expires_at }}', null, null],
        ],
        'es' => [
            [EmailBlock::TYPE_HEADING, 'Confirma tu dirección de email', EmailBlock::LEVEL_H1, null, null, null],
            [EmailBlock::TYPE_TEXT, null, null, 'Gracias por registrarte. Haz clic en el botón de abajo para confirmar tu dirección de email.', null, null],
            [EmailBlock::TYPE_BUTTON, null, null, null, 'Confirmar mi email', '{{ signed_url }}'],
            [EmailBlock::TYPE_TEXT, null, null, '{{ expires_at }}', null, null],
        ],
    ];

    // "{{ reset_url }}"/"{{ expires_at }}" are resolved by the scaffolded ResetPasswordRequestFormAction
    private const PASSWORD_RESET_BLOCKS = [
        'fr' => [
            [EmailBlock::TYPE_HEADING, 'Réinitialisation de votre mot de passe', EmailBlock::LEVEL_H1, null, null, null],
            [EmailBlock::TYPE_TEXT, null, null, 'Vous avez demandé la réinitialisation de votre mot de passe. Cliquez sur le bouton ci-dessous pour en choisir un nouveau.', null, null],
            [EmailBlock::TYPE_BUTTON, null, null, null, 'Réinitialiser mon mot de passe', '{{ reset_url }}'],
            [EmailBlock::TYPE_TEXT, null, null, '{{ expires_at }}', null, null],
        ],
        'en' => [
            [EmailBlock::TYPE_HEADING, 'Reset your password', EmailBlock::LEVEL_H1, null, null, null],
            [EmailBlock::TYPE_TEXT, null, null, 'You requested a password reset. Click the button below to choose a new one.', null, null],
            [EmailBlock::TYPE_BUTTON, null, null, null, 'Reset my password', '{{ reset_url }}'],
            [EmailBlock::TYPE_TEXT, null, null, '{{ expires_at }}', null, null],
        ],
        'es' => [
            [EmailBlock::TYPE_HEADING, 'Restablece tu contraseña', EmailBlock::LEVEL_H1, null, null, null],
            [EmailBlock::TYPE_TEXT, null, null, 'Has solicitado restablecer tu contraseña. Haz clic en el botón de abajo para elegir una nueva.', null, null],
            [EmailBlock::TYPE_BUTTON, null, null, null, 'Restablecer mi contraseña', '{{ reset_url }}'],
            [EmailBlock::TYPE_TEXT, null, null, '{{ expires_at }}', null, null],
        ],
    ];

    public function __construct(
        private readonly FormSeeder $formSeeder,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // The whole account-creation flow: the form itself, plus the email its action sends
    public function ensureRegisterForm(): void
    {
        $this->formSeeder->ensureForm('register', self::REGISTER_CORE_FIELDS, 'register', linksByLocale: self::REGISTER_LINKS);
        $this->formSeeder->ensureEmailTemplate(EmailVerifier::EMAIL_TEMPLATE, self::ACCOUNT_VALIDATION_BLOCKS);
    }

    public function ensureResetPasswordRequestForm(): void
    {
        $this->formSeeder->ensureForm('reset_password_request', self::RESET_PASSWORD_REQUEST_CORE_FIELDS, 'reset_password_request', linksByLocale: self::RESET_PASSWORD_REQUEST_LINKS);
        $this->formSeeder->ensureEmailTemplate('password_reset', self::PASSWORD_RESET_BLOCKS);
    }

    // Both flows in one go, flushed - what a caller with nothing else to seed wants (see c975l:config:user-create); a caller batching several seeds calls the two methods above and flushes once itself
    public function ensureAll(): void
    {
        $this->ensureRegisterForm();
        $this->ensureResetPasswordRequestForm();
        $this->entityManager->flush();
    }
}
