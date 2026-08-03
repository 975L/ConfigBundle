<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\RegisterFormAction;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\UserRegistrar;
use c975L\UiBundle\Entity\Form;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegisterFormActionTest extends TestCase
{
    private function createConfigService(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'email-from' => 'noreply@example.test',
                'email-from-name' => 'Example',
                'site-name' => 'Example',
                default => null,
            }
        );

        return $configService;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    public function testGetKeyReturnsRegister(): void
    {
        $action = new RegisterFormAction(
            $this->createStub(UserRepository::class),
            $this->createStub(UserRegistrar::class),
            $this->createConfigService(),
            $this->createTranslator(),
        );

        $this->assertSame('register', $action->getKey());
    }

    // Silently succeeds (same generic "form_submitted" flash as a real success) without creating anything or sending any email - never reveals which emails are already registered
    public function testHandleReturnsTrueSilentlyWithoutRegisteringWhenEmailAlreadyExists(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn(new User());

        $userRegistrar = $this->createMock(UserRegistrar::class);
        $userRegistrar->expects($this->never())->method('register');

        $action = new RegisterFormAction($userRepository, $userRegistrar, $this->createConfigService(), $this->createTranslator());

        $this->assertTrue($action->handle(new Form(), ['email' => 'taken@example.test', 'plainPassword' => 'Str0ng!Password']));
    }

    public function testHandleRegistersNewUserAndReturnsTrueWhenEmailIsFree(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn(null);

        $userRegistrar = $this->createMock(UserRegistrar::class);
        $userRegistrar->expects($this->once())->method('register')->with(
            $this->callback(static fn (User $user): bool => 'new@example.test' === $user->getEmail()),
            'Str0ng!Password',
            'app_verify_email',
            'Example - label.confirm_your_email',
            'new@example.test',
        )->willReturn(true);

        $action = new RegisterFormAction($userRepository, $userRegistrar, $this->createConfigService(), $this->createTranslator());

        $this->assertTrue($action->handle(new Form(), ['email' => 'new@example.test', 'plainPassword' => 'Str0ng!Password']));
    }
}
