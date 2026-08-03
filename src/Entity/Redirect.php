<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Entity;

use c975L\ConfigBundle\Repository\RedirectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RedirectRepository::class)]
#[ORM\Table(name: 'site_redirect')]
#[UniqueEntity('fromPath')]
class Redirect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $fromPath = null;

    // Nullable since a "gone" row has nothing to redirect to - still required for every other one, hence the conditional constraint rather than a plain NotBlank
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\When(
        expression: 'false === this.isGone()',
        constraints: [new Assert\NotBlank()],
    )]
    private ?string $toUrl = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $permanent = true;

    // Answers 410 Gone rather than redirecting - for a url removed with no equivalent to send anyone to (a documentation folder that no longer exists, the routes of a previous architecture). Search engines drop a 410 far faster than the plain 404 the same url would otherwise return, and unlike a Page in the trash (see PageController::display(), whose own 410 only lasts as long as the page can still be restored) this one is permanent and covers any path, not just "/pages/<slug>"
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $gone = false;

    public function __toString(): string
    {
        return $this->fromPath ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromPath(): ?string
    {
        return $this->fromPath;
    }

    public function setFromPath(string $fromPath): self
    {
        $this->fromPath = '/' . ltrim($fromPath, '/');

        return $this;
    }

    public function getToUrl(): ?string
    {
        return $this->toUrl;
    }

    public function setToUrl(?string $toUrl): self
    {
        $this->toUrl = $toUrl;

        return $this;
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }

    public function setPermanent(bool $permanent): self
    {
        $this->permanent = $permanent;

        return $this;
    }

    public function isGone(): bool
    {
        return $this->gone;
    }

    public function setGone(bool $gone): self
    {
        $this->gone = $gone;

        return $this;
    }
}
