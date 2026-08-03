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
use c975L\ConfigBundle\Service\UserFormSeeder;
use c975L\UiBundle\Entity\EmailBlock;
use c975L\UiBundle\Entity\EmailTemplate;
use c975L\UiBundle\Entity\Form;
use c975L\UiBundle\Entity\FormField;
use c975L\UiBundle\Repository\EmailTemplateRepository;
use c975L\UiBundle\Repository\FormRepository;
use c975L\UiBundle\Service\FormSeeder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class UserFormSeederTest extends TestCase
{
    private function createSeeder(array &$persisted, array $existingForms = [], array $existingTemplates = [], string $defaultLocale = 'fr', bool $existingFormsHaveLinks = true): UserFormSeeder
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        // An existing Form comes back with its action already set - both flows name their FormActionInterface key after the Form itself - and, unless the caller asks for a site seeded before links existed, with its links too, so nothing is left for FormSeeder to backfill
        $formRepository = $this->createStub(FormRepository::class);
        $formRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($existingForms, $existingFormsHaveLinks): ?Form {
                if (!\in_array($criteria['name'], $existingForms, true)) {
                    return null;
                }

                $form = (new Form())->setName($criteria['name'])->setAction($criteria['name'])->setRestricted(true);

                return $existingFormsHaveLinks ? $form->setLinks([['label' => 'Me connecter', 'url' => '/login']]) : $form;
            }
        );

        $emailTemplateRepository = $this->createStub(EmailTemplateRepository::class);
        $emailTemplateRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?EmailTemplate => \in_array($criteria['name'], $existingTemplates, true) ? new EmailTemplate() : null
        );

        return new UserFormSeeder(
            new FormSeeder($entityManager, $formRepository, $emailTemplateRepository, $defaultLocale),
            $entityManager
        );
    }

    /** @return list<Form> */
    private function forms(array $persisted): array
    {
        return array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Form));
    }

    /** @return list<EmailTemplate> */
    private function emailTemplates(array $persisted): array
    {
        return array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof EmailTemplate));
    }

    // The "register" Form and the email its action sends, both restricted so an admin can retitle the fields but not rename or drop them
    public function testEnsureRegisterFormSeedsTheFormAndItsValidationEmail(): void
    {
        $persisted = [];
        $this->createSeeder($persisted)->ensureRegisterForm();

        $form = $this->forms($persisted)[0];
        $this->assertSame('register', $form->getName());
        $this->assertSame('register', $form->getAction());
        $this->assertTrue($form->isRestricted());
        $this->assertSame(['email', 'plainPassword', 'cgu'], array_map(static fn (FormField $field): string => $field->getName(), $form->getFields()->toArray()));

        $emailTemplate = $this->emailTemplates($persisted)[0];
        $this->assertSame(EmailVerifier::EMAIL_TEMPLATE, $emailTemplate->getName());
        $this->assertTrue($emailTemplate->isRestricted());
    }

    // "{{ signed_url }}"/"{{ expires_at }}" are what EmailVerifier resolves at send time - a template seeded without them would send a confirmation email with no link in it
    public function testTheValidationEmailCarriesThePlaceholdersEmailVerifierResolves(): void
    {
        $persisted = [];
        $this->createSeeder($persisted)->ensureRegisterForm();

        $blocks = $this->emailTemplates($persisted)[0]->getBlocks()->toArray();
        $button = array_values(array_filter($blocks, static fn (EmailBlock $block): bool => EmailBlock::TYPE_BUTTON === $block->getType()))[0];

        $this->assertSame('{{ signed_url }}', $button->getUrl());
        $this->assertContains('{{ expires_at }}', array_map(static fn (EmailBlock $block): ?string => $block->getContent(), $blocks));
    }

    public function testEnsureResetPasswordRequestFormSeedsTheFormAndItsResetEmail(): void
    {
        $persisted = [];
        $this->createSeeder($persisted)->ensureResetPasswordRequestForm();

        $form = $this->forms($persisted)[0];
        $this->assertSame('reset_password_request', $form->getName());
        $this->assertSame('reset_password_request', $form->getAction());
        $this->assertSame(['email'], array_map(static fn (FormField $field): string => $field->getName(), $form->getFields()->toArray()));

        $emailTemplate = $this->emailTemplates($persisted)[0];
        $this->assertSame('password_reset', $emailTemplate->getName());
        $blocks = $emailTemplate->getBlocks()->toArray();
        $button = array_values(array_filter($blocks, static fn (EmailBlock $block): bool => EmailBlock::TYPE_BUTTON === $block->getType()))[0];
        $this->assertSame('{{ reset_url }}', $button->getUrl());
    }

    // Idempotent: re-running it on a site that already has both flows writes nothing
    public function testNothingIsSeededTwice(): void
    {
        $persisted = [];
        $seeder = $this->createSeeder(
            $persisted,
            ['register', 'reset_password_request'],
            [EmailVerifier::EMAIL_TEMPLATE, 'password_reset']
        );

        $seeder->ensureRegisterForm();
        $seeder->ensureResetPasswordRequestForm();

        $this->assertSame([], $this->emailTemplates($persisted));
        $this->assertSame([], $this->forms($persisted));
    }

    // A visitor who already has an account, or who lost their password, needs a way out of the register form
    public function testTheRegisterFormCarriesTheLinksOutOfIt(): void
    {
        $persisted = [];
        $this->createSeeder($persisted)->ensureRegisterForm();

        $this->assertSame(
            ['/login', '/pages/mot-de-passe-oublie'],
            array_column($this->forms($persisted)[0]->getLinks(), 'url')
        );
    }

    public function testTheResetPasswordRequestFormLinksBackToSignInAndRegister(): void
    {
        $persisted = [];
        $this->createSeeder($persisted)->ensureResetPasswordRequestForm();

        $this->assertSame(
            ['/login', '/pages/creer-un-compte'],
            array_column($this->forms($persisted)[0]->getLinks(), 'url')
        );
    }

    // A site seeded before the two forms declared any link gets them back, without anything else being rewritten
    public function testAnAlreadySeededFormGetsItsMissingLinksBackfilled(): void
    {
        $persisted = [];
        $seeder = $this->createSeeder(
            $persisted,
            ['register'],
            [EmailVerifier::EMAIL_TEMPLATE],
            existingFormsHaveLinks: false
        );

        $seeder->ensureRegisterForm();

        $this->assertSame(['/login', '/pages/mot-de-passe-oublie'], array_column($this->forms($persisted)[0]->getLinks(), 'url'));
    }

    // Form::$name is unique site-wide, so one locale's labels are picked once - kernel.default_locale's, falling back on "en" for a locale the seeder ships no wording for
    public function testFieldLabelsFollowTheDefaultLocaleAndFallBackOnEnglish(): void
    {
        $persisted = [];
        $this->createSeeder($persisted, defaultLocale: 'es')->ensureRegisterForm();
        $this->assertSame('Contraseña', $this->forms($persisted)[0]->getFields()->toArray()[1]->getLabel());

        $persisted = [];
        $this->createSeeder($persisted, defaultLocale: 'de')->ensureRegisterForm();
        $this->assertSame('Password', $this->forms($persisted)[0]->getFields()->toArray()[1]->getLabel());
    }
}
