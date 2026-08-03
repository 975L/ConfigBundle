<?php

namespace App\Tests\Form;

use App\Form\ChangePasswordFormType;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Constraints\NotCompromisedPasswordValidator;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

// TypeTestCase::setUp() creates an EventDispatcherInterface mock without expectations; opt out of PHPUnit's stub-instead-of-mock notice for it.
#[AllowMockObjectsWithoutExpectations]
class ChangePasswordFormTypeTest extends TypeTestCase
{
    // Uses a real validator so the Length constraint is actually exercised, but with NotCompromisedPassword disabled to avoid a real HTTP call to the haveibeenpwned API during tests.
    protected function getExtensions(): array
    {
        $validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                NotCompromisedPasswordValidator::class => new NotCompromisedPasswordValidator(null, 'UTF-8', false),
            ]))
            ->getValidator();

        return [
            new ValidatorExtension($validator),
            new PreloadedExtension([new ChangePasswordFormType()], []),
        ];
    }

    public function testSubmitValidDataReturnsPlainPassword(): void
    {
        $form = $this->factory->create(ChangePasswordFormType::class);
        $form->submit([
            'plainPassword' => [
                'first' => 'Str0ngPassword!',
                'second' => 'Str0ngPassword!',
            ],
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertTrue($form->isValid());
        $this->assertSame('Str0ngPassword!', $form->get('plainPassword')->getData());
    }

    public function testSubmitInvalidWhenConfirmationMismatches(): void
    {
        $form = $this->factory->create(ChangePasswordFormType::class);
        $form->submit([
            'plainPassword' => [
                'first' => 'Str0ngPassword!',
                'second' => 'AnotherPassword1!',
            ],
        ]);

        $this->assertFalse($form->isValid());
    }

    public function testSubmitInvalidWhenPasswordTooShort(): void
    {
        $form = $this->factory->create(ChangePasswordFormType::class);
        $form->submit([
            'plainPassword' => [
                'first' => 'Aa1!',
                'second' => 'Aa1!',
            ],
        ]);

        $this->assertFalse($form->isValid());
    }

    public function testPlainPasswordIsNotMapped(): void
    {
        $form = $this->factory->create(ChangePasswordFormType::class);

        $this->assertFalse($form->get('plainPassword')->getConfig()->getMapped());
    }
}
