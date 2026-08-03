<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Fixtures;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

// Stands in for App\Entity\User (app-space, not autoloadable from this bundle checkout) in tests for c975L\ConfigBundle\Service\EmailVerifier/UserRegistrar/PasswordResetter - those only rely on UserInterface/PasswordAuthenticatedUserInterface plus method_exists() duck-typing for getId()/setCreation()/setModification()/setIsVerified()/setIsEnabled(), all provided here
class UserStub implements UserInterface, PasswordAuthenticatedUserInterface
{
    private ?int $id = null;
    private string $email;
    private ?string $password = null;
    private bool $verified = false;
    private bool $enabled = false;
    private ?\DateTime $creation = null;
    private ?\DateTime $modification = null;

    public function __construct(string $email = 'user@example.test')
    {
        $this->email = $email;
    }

    public function withId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setIsVerified(bool $verified): static
    {
        $this->verified = $verified;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setIsEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getCreation(): ?\DateTime
    {
        return $this->creation;
    }

    public function setCreation(\DateTime $creation): static
    {
        $this->creation = $creation;

        return $this;
    }

    public function getModification(): ?\DateTime
    {
        return $this->modification;
    }

    public function setModification(\DateTime $modification): static
    {
        $this->modification = $modification;

        return $this;
    }
}
